<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Cart;

class CartService
{
    /**
     * カート内商品の一覧を取得（指摘#8 修正済み）
     * 【指摘①】数量取得の Cart クエリに user_id 条件がなく、他ユーザーの数量まで合算されていた。
     *          ->where('user_id', $item->user_id) を追加。
     * 【指摘②】Product::findOrFail() の直後に同一 product_id で Product::where()->get() しており二重クエリ。
     *          取得済みの $p を再利用して配列を組み立てるよう修正。
     */
    public static function getItemsInCart($items)
    {
        $products = [];

        foreach ($items as $item) {
            $p = Product::findOrFail($item->product_id);
            $owner = $p->shop->owner;
            $ownerInfo = [
                'ownerName' => $owner->name,
                'email' => $owner->email
            ];

            // 修正: user_id でフィルタし、他ユーザー分を合算しない
            $quantity = Cart::where('product_id', $item->product_id)
                ->where('user_id', $item->user_id)
                ->select('quantity')->get()->toArray();

            // 修正: 二重クエリを避け、取得済みの $p から id/name/price を組み立て
            $result = array_merge(
                ['id' => $p->id, 'name' => $p->name, 'price' => $p->price],
                $ownerInfo,
                $quantity[0]
            );

            array_push($products, $result);
        }

        return $products;
    }
}
