# 課題2：Stripe 決済修正 — Webhook 方式を使わない場合の修正方針

添付資料「2. 課題ごとの詳細評価」の課題2について、**動画教材で Webhook 方式を行っていない**前提で、評価指摘に対応できる修正箇所を整理しました。

---

## 評価指摘の要約

- **✅ すでに正しい**: line_items の二重配列修正、price_data 形式、config() 経由の API キー取得
- **⚠️ 問題として指摘されている**:
  1. **トランザクション内に外部 API 呼び出し**（Stripe を `DB::transaction` のクロージャ内で呼んでいる）
  2. **cancel() の在庫戻し**（Webhook 方式なら不要になる、という説明）

---

## Webhook 方式を採用しない場合の方針

- **Webhook は導入しない**（動画教材に合わせる）
- **そのうえでできること**: 「トランザクション内で Stripe を叩く」アンチパターンだけを解消する

---

## 修正すべき箇所（Webhook なしで対応する内容）

### 1. トランザクション内から Stripe 呼び出しを外す（必須）

**問題点（評価資料の通り）**

- `DB::transaction` の中で `\Stripe\Checkout\Session::create()` を実行している
- その間、DB 接続を占有したまま Stripe の HTTP 応答を待つ → 接続プール枯渇のリスク
- 外部 API と DB のロールバックが独立しているため、データ不整合の可能性がある

**修正方針（Webhook は使わない）**

- **トランザクションでは「在庫のロック・チェック・減算」だけを行う**
- **トランザクションがコミットしたあとで**、Stripe の `Checkout\Session::create()` を呼ぶ
- Stripe が失敗した場合は、**すでに減らした在庫を戻す**処理を別トランザクションで実行してから、エラー用リダイレクト

**具体的な流れ**

1. 在庫チェック用に `$lineItems` を組み立て（現状のまま）
2. **第1の DB::transaction**
   - `lockForUpdate()` で在庫取得
   - 不足なら `throw new \Exception('在庫不足')`
   - 問題なければ在庫減算（`Stock::create` で reduce）のみ
   - **Stripe の呼び出しはこの中に書かない**
3. トランザクションが正常終了したら、**その外で** `\Stripe\Stripe::setApiKey(...)` と `\Stripe\Checkout\Session::create(...)` を実行
4. Stripe が例外を投げた場合:
   - 今ユーザーに紐づくカート内容（`$user->products` / pivot の quantity）を使って、**第2の DB::transaction** で在庫を戻す（`Stock::create` で add）
   - その後、`redirect()->route('user.cart.index')->with(['message' => '決済の開始に失敗しました。', ...])` などでエラー表示
5. 在庫不足の `Exception` は従来どおり、`catch` で「在庫不足です。」でリダイレクト

**変更対象ファイル・メソッド**

- `app/Http/Controllers/User/CartController.php` の `checkout()` メソッド

**注意**

- 「Stripe は成功したが、その後の処理で DB が失敗する」ようなケースは、この構成ではほぼ発生しない（先に DB で減算し、成功したら Stripe を呼ぶため）
- 「Stripe が失敗したら在庫を戻す」処理を必ず実装する必要がある

---

### 2. cancel() の在庫戻し（現状のままでよい）

**評価資料の記述**

- 「Webhook 方式に移行した場合、checkout() で在庫を減らさないため、cancel() での在庫戻しは不要になる」

**Webhook 方式を採用しない場合**

- checkout() で**従来どおり在庫を減算する**ため、ユーザーが Stripe の画面で「キャンセル」したときには、**在庫を戻す必要がある**
- したがって、**cancel() の在庫戻し処理は削除せず、現状のまま残す**のが正しい

**結論**: 修正不要。コメントで「Webhook 方式でないため、checkout 時に在庫減算しているので cancel 時は在庫を戻す」旨を書いておくとよい。

---

## まとめ（やること一覧）

| 項目 | 対応 |
|------|------|
| トランザクション内の Stripe 呼び出し | 在庫のトランザクションと分離し、Stripe はトランザクション外で実行。Stripe 失敗時は在庫を戻す処理を追加 |
| cancel() の在庫戻し | そのまま残す（Webhook を使わないため） |
| Webhook（checkout.session.completed 等） | 動画に合わせて導入しない |

上記を実装すれば、**Webhook を使わずに**「トランザクション内に外部 API を含める」という指摘には対応できます。
