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
// 指摘#5: add() で quantity / product_id のバリデーションを行う FormRequest
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
     * チェックアウト（指摘#2・#3・#9 修正済み）
     * 【#2】line_items: 二重配列 [$lineItems] → $lineItems。各 item は廃止された name/amount/currency 形式ではなく
     *       price_data + product_data 形式（Stripe v7 以降の Checkout Session API）に変更。
     * 【#3】在庫減算と Stripe 呼び出しを DB::transaction で囲み、Stripe 失敗時は在庫をロールバック。
     * 【#9】env() 直接使用をやめ、config('services.stripe.secret'|'public') で取得。
     */
    public function checkout()
    {
        $user = User::findOrFail(Auth::id());
        $products = $user->products;

        $lineItems = [];
        foreach($products as $product){
            $quantity = '';
            $quantity = Stock::where('product_id', $product->id)->sum('quantity');

            if($product->pivot->quantity > $quantity){
                return redirect()->route('user.cart.index');
            } else {
                // 指摘#2 修正: 旧形式ではなく price_data 形式で渡す（v7 以降で必須）
                $lineItem = [
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
                array_push($lineItems, $lineItem);
            }
        }

        $session = null;
        // 指摘#9 修正: env() ではなく config 経由で取得（config:cache 後も動作するため）
        $publicKey = config('services.stripe.public');

        // 指摘#3 修正: Stripe 失敗時に在庫減算をロールバックするためトランザクションで囲む
        try {
            DB::transaction(function () use ($products, $lineItems, &$session) {
                foreach ($products as $product) {
                    Stock::create([
                        'product_id' => $product->id,
                        'type' => \Constant::PRODUCT_LIST['reduce'],
                        'quantity' => $product->pivot->quantity * -1
                    ]);
                }

                \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

                // 指摘#2 修正: line_items は配列のまま渡す（[$lineItems] の二重配列は誤り）
                $session = \Stripe\Checkout\Session::create([
                    'payment_method_types' => ['card'],
                    'line_items' => $lineItems,
                    'mode' => 'payment',
                    'success_url' => route('user.cart.success'),
                    'cancel_url' => route('user.cart.cancel'),
                ]);
            });
        } catch (\Throwable $e) {
            return redirect()->route('user.cart.index')
                ->with(['message' => '決済の開始に失敗しました。', 'status' => 'alert']);
        }

        return view('user.checkout',
            compact('session', 'publicKey'));
    }

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
