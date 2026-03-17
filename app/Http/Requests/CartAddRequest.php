<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 指摘#5 / 課題4: バリデーション強化 対応。CartController::add() 用 FormRequest。
 * 【指摘・課題4】add() で quantity・product_id をそのまま使っており、負の数・存在しないIDで
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

    /**
     * バリデーションエラーメッセージ（日本語）。
     *
     * @return array
     */
    public function messages()
    {
        return [
            'quantity.required' => '数量を入力してください。',
            'quantity.integer'  => '数量は整数で入力してください。',
            'quantity.min'      => '数量は1以上を指定してください。',
            'quantity.max'      => '数量は99以下を指定してください。',
            'product_id.exists' => '指定された商品が存在しません。',
        ];
    }
}
