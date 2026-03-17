<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Cart;
use App\Models\User;
use App\Models\Stock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\CartService;
use App\Jobs\SendThanksMail;
use App\Jobs\SendOrderedMail;
// 指摘#5 / 課題4: add() で quantity / product_id のバリデーションを行う FormRequest
use App\Http\Requests\CartAddRequest;

class CartController extends Controller
{
    public function index()
    {
        $user = User::findOrFail(Auth::id());
        $products = $user->products;
        $totalPrice = 0;

        foreach($products as $product){
            $totalPrice += $product->price * $product->pivot->quantity;
        }

        //dd($products, $totalPrice);

        return view('user.cart',
            compact('products', 'totalPrice'));
    }

    /**
     * カートに商品を追加（指摘#5 修正済み）
     * 【指摘】quantity・product_id にバリデーションがなく、負の数・存在しないIDで孤立レコードが作れる。
     * CartAddRequest で quantity: integer|min:1|max:99, product_id: exists:products,id を検証。
     */
    public function add(CartAddRequest $request)
    {
        $itemInCart = Cart::where('product_id', $request->product_id)
        ->where('user_id', Auth::id())->first();

        if($itemInCart){
            $itemInCart->quantity += $request->quantity;
            $itemInCart->save();

        } else {
            Cart::create([
                'user_id' => Auth::id(),
                'product_id' => $request->product_id,
                'quantity' => $request->quantity
            ]);
        }

        return redirect()->route('user.cart.index');
    }

    public function delete($id)
    {
        Cart::where('product_id', $id)
        ->where('user_id', Auth::id())
        ->delete();

        return redirect()->route('user.cart.index');
    }

    /**
     * チェックアウト（指摘#2・#3・#9 修正済み / 課題1: 悲観的ロック / 課題2: トランザクションとStripe分離）
     * 【#2】line_items: 二重配列 [$lineItems] → $lineItems。price_data + product_data 形式（Stripe v7 以降）。
     * 【#3】Stripe 失敗時は在庫を戻す（課題2でトランザクションとStripeを分離したため、Stripe失敗時に明示的に在庫戻し）。
     * 【#9】env() ではなく config('services.stripe.secret'|'public') で取得。
     * 【課題1】在庫の取得・チェック・減算を同一トランザクション内で lockForUpdate() により悲観的ロック。
     * 【課題2】トランザクション内に外部API（Stripe）を含めない。在庫減算のみトランザクションで行い、コミット後にStripe呼び出し。Stripe失敗時は在庫を戻す。
     */
    public function checkout()
    {
        $user = User::findOrFail(Auth::id());
        $products = $user->products;

        // 【課題1・課題2】lineItems は在庫数に依存しないためトランザクション外で組み立て。在庫チェック・減算は後述のトランザクション内で実施（課題1: 悲観的ロック / 課題2: Stripe はトランザクション外で呼ぶため）。
        $lineItems = [];
        foreach ($products as $product) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'jpy',
                    'unit_amount' => $product->price,
                    'product_data' => [
                        'name' => $product->name,
                        'description' => $product->information,
                    ],
                ],
                'quantity' => $product->pivot->quantity,
            ];
        }

        // 指摘#9 修正: env() ではなく config 経由で取得（config:cache 後も動作するため）
        $publicKey = config('services.stripe.public');

        // 【課題2】第1トランザクション: 在庫のロック・チェック・減算のみ。Stripe はトランザクション外で呼ぶ（DB接続を占有したままHTTPを待たない）。Stripe 失敗時の在庫戻しは後述の catch で別トランザクション実行。
        try {
            DB::transaction(function () use ($products) {
                // 【課題1】在庫チェックをトランザクション内で実施し、lockForUpdate() で悲観的ロックを取得。
                // 修正前: トランザクション外で sum('quantity') のみ実行していたため、チェックと減算の間に他リクエストが割り込み在庫マイナスになる可能性があった。
                // 修正後: SELECT ... FOR UPDATE により行をロックし、同一トランザクション内でチェック→減算まで行うことで Race Condition を防止。不足時は Exception でロールバック。
                foreach ($products as $product) {
                    $quantity = Stock::where('product_id', $product->id)
                        ->lockForUpdate()
                        ->sum('quantity');
                    if ($product->pivot->quantity > $quantity) {
                        throw new \Exception('在庫不足');
                    }
                }
                // 【課題1】上記で全商品の在庫をロック・チェック済みのため、このタイミングで在庫減算を行っても他トランザクションは割り込めない。
                foreach ($products as $product) {
                    Stock::create([
                        'product_id' => $product->id,
                        'type' => \Constant::PRODUCT_LIST['reduce'],
                        'quantity' => $product->pivot->quantity * -1
                    ]);
                }
            });
        } catch (\Throwable $e) {
            // 【課題1】在庫不足で throw した場合は専用メッセージでカート一覧へリダイレクト
            if ($e->getMessage() === '在庫不足') {
                return redirect()->route('user.cart.index')
                    ->with(['message' => '在庫不足です。', 'status' => 'alert']);
            }
            // 在庫不足以外（DBエラー等）はトランザクションがロールバック済みのため在庫戻し不要
            return redirect()->route('user.cart.index')
                ->with(['message' => '決済の開始に失敗しました。', 'status' => 'alert']);
        }

        // 【課題2】トランザクション完了後に Stripe を呼び出す。失敗したら在庫を戻す。
        try {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items' => $lineItems,
                'mode' => 'payment',
                'success_url' => route('user.cart.success'),
                'cancel_url' => route('user.cart.cancel'),
            ]);
        } catch (\Throwable $e) {
            // 【課題2】Stripe 失敗時: すでに減算した在庫を戻す（データ不整合を防ぐ）
            DB::transaction(function () use ($products) {
                foreach ($products as $product) {
                    Stock::create([
                        'product_id' => $product->id,
                        'type' => \Constant::PRODUCT_LIST['add'],
                        'quantity' => $product->pivot->quantity
                    ]);
                }
            });
            return redirect()->route('user.cart.index')
                ->with(['message' => '決済の開始に失敗しました。', 'status' => 'alert']);
        }

        return view('user.checkout',
            compact('session', 'publicKey'));
    }

    /**
     * Stripe 決済完了後のコールバック。メール送信（ThanksMail / OrderedMail）を dispatch し、カートを削除して商品一覧へリダイレクト。
     */
    public function success()
    {
        ////
        $items = Cart::where('user_id', Auth::id())->get();
        $products = CartService::getItemsInCart($items);
        $user = User::findOrFail(Auth::id());

        SendThanksMail::dispatch($products, $user);
        foreach($products as $product)
        {
            SendOrderedMail::dispatch($product, $user);
        }
        // dd('ユーザーメール送信テスト');
        ////
        Cart::where('user_id', Auth::id())->delete();

        return redirect()->route('user.items.index');
    }

    /**
     * Stripe チェックアウト画面でユーザーがキャンセルしたときのコールバック。
     * 【課題2】Webhook 方式ではないため checkout() で在庫を事前減算している。キャンセル時は在庫を戻す必要がある（Webhook 方式なら checkout で減算しないため不要になる）。
     */
    public function cancel()
    {
        $user = User::findOrFail(Auth::id());

        foreach($user->products as $product){
            Stock::create([
                'product_id' => $product->id,
                'type' => \Constant::PRODUCT_LIST['add'],
                'quantity' => $product->pivot->quantity
            ]);
        }

        return redirect()->route('user.cart.index');
    }
}
