<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 指摘#5 対応: CartController::add() 用 FormRequest。
 * 【指摘】add() で quantity・product_id をそのまま使っており、負の数・存在しないIDで
 * 孤立したカートレコードが作れる。本クラスでバリデーションを実施。
 */
class CartAddRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     * quantity: 1〜99 の整数、product_id: products テーブルに存在する ID のみ許可。
     *
     * @return array
     */
    public function rules()
    {
        return [
            'quantity' => 'required|integer|min:1|max:99',
            'product_id' => 'required|integer|exists:products,id',
        ];
    }
}
