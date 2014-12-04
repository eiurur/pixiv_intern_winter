Pixiv_intern_winter
======

必須課題
=======

**初期スコア**

    $ benchmarker bench

1444

**変更項目**

- == を === に置き換えた。
  - 処理速度は型変換を行わない`===`に分があるため。
- `bindValue`の`data_type`に明示的なデータ型を指定した。
  - 動的な型変換がなくなるため。
- `last_login()`関数のなかで`curret_user()`関数を呼ばず、$user変数を引数として渡す。
  - 無駄なSQLクエリを投げずに済むため。

**最終スコア**

    $ benchmarker bench

1601

任意課題
=======

**変更項目**

- 失敗回数を記録するテーブルを新たに作成して、そっちにipとuser_idごとに失敗した回数を追加or追記する。
  - 検索件数が減るため。
  - サブクエリを除去できるため。
- ログインが成功した履歴を専用のテーブルに保存し、そこから前回ログインした日時と最終ログインIPを取得するようにした。
  - 検索件数が減るため。
  - 検索条件が単純になるため。
- `/etc/nginx/nginx.conf`の`worker_processes`を16に変更
  - ワーカー数を増やすため
- `/etc/nginx/nginx.conf`の`worker_rlimit_nofile`を4096に変更
  - Too many open filesエラー対策用に`worker_connections`の4倍の値にした。
- `/etc/nginx/nginx.conf`の`keepalive_timeout`を0に変更
  - `$ benchmarker b --workload 16`を実行したところ、`type:fail      reason:Get http://localhost/: dial tcp 127.0.0.1:80: cannot assign requested address    method:GET      uri:/`というエラーが吐かれた。TCP接続の使いまわしを防ぐため、keepaliveを無効にした。

**最終スコア**

    $ benchmarker b --workload 16

24714

