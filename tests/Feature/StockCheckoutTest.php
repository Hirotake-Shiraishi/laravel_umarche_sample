<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Cart;
use App\Models\Owner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 課題1: 在庫管理・悲観的ロックの Feature テスト。
 * 評価資料「在庫1個の商品が表示される」「在庫不足時にリダイレクトされる」の2ケースを検証する。
 */
class StockCheckoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 商品一覧表示に必要なショップ・カテゴリ・画像をシードする。
     * Product::availableItems() が JOIN する shops / secondary_categories / images を用意するため。
     * 外部キー順にすべてテスト内で DB に直接挿入（RefreshDatabase 時の Seeder で FK 不整合が出るため）。
     */
    protected function seedItemListDependencies(): void
    {
        $now = now();
        DB::table('owners')->insert([
            ['id' => 1, 'name' => 'owner1', 'email' => 'owner1@example.com', 'password' => Hash::make('password'), 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'owner2', 'email' => 'owner2@example.com', 'password' => Hash::make('password'), 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('primary_categories')->insert([
            ['id' => 1, 'name' => 'キッズ', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('secondary_categories')->insert([
            ['id' => 1, 'name' => '靴', 'sort_order' => 1, 'primary_category_id' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('shops')->insert([
            ['id' => 1, 'owner_id' => 1, 'name' => 'テスト店', 'information' => '説明', 'filename' => 'sample1.jpg', 'is_selling' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('images')->insert([
            ['id' => 1, 'owner_id' => 1, 'filename' => 'sample1.jpg', 'title' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * 課題1 検証: 在庫1個の商品が商品一覧に表示されること。
     * 修正内容: scopeAvailableItems の having('quantity', '>', 0) により在庫1個でも一覧に出るようになったことを確認する。
     */
    public function test_product_with_one_stock_appears_in_item_list(): void
    {
        $this->seedItemListDependencies();

        // 在庫1個の商品とその Stock を1件作成（having > 0 なら一覧に含まれる）
        $product = Product::create([
            'shop_id' => 1,
            'name' => '在庫1個のテスト商品',
            'information' => 'テスト説明',
            'price' => 1000,
            'is_selling' => true,
            'sort_order' => 1,
            'secondary_category_id' => 1,
            'image1' => 1,
            'image2' => 1,
            'image3' => 1,
            'image4' => 1,
        ]);

        Stock::create([
            'product_id' => $product->id,
            'type' => 1,
            'quantity' => 1,
        ]);

        $user = User::factory()->create();

        // 商品一覧は /items（指摘#10 で / から変更）
        $response = $this->actingAs($user, 'users')->get('/items');

        $response->assertStatus(200);
        $response->assertSee('在庫1個のテスト商品');
    }

    /**
     * 課題1 検証: 在庫不足時にチェックアウトでカート一覧へリダイレクトされ、セッションに「在庫不足です。」が入ること。
     * 修正内容: checkout() 内で lockForUpdate 取得後に在庫チェックし、不足時は Exception('在庫不足') で throw → catch でリダイレクトする挙動を確認する。
     */
    public function test_checkout_redirects_to_cart_index_when_insufficient_stock(): void
    {
        $this->seedItemListDependencies();

        // 在庫1個の商品に対して、カートには2個入れた状態にする
        $product = Product::create([
            'shop_id' => 1,
            'name' => '在庫1個の商品',
            'information' => '説明',
            'price' => 500,
            'is_selling' => true,
            'sort_order' => 1,
            'secondary_category_id' => 1,
            'image1' => 1,
            'image2' => 1,
            'image3' => 1,
            'image4' => 1,
        ]);

        Stock::create([
            'product_id' => $product->id,
            'type' => 1,
            'quantity' => 1,
        ]);

        $user = User::factory()->create();

        Cart::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        // チェックアウト実行 → 在庫不足でトランザクション内で throw され、cart.index へリダイレクトされることを検証
        $response = $this->actingAs($user, 'users')->get(route('user.cart.checkout'));

        $response->assertRedirect(route('user.cart.index'));
        $response->assertSessionHas('message', '在庫不足です。');
        $response->assertSessionHas('status', 'alert');
    }
}
