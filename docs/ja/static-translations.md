# 静的メッセージの翻訳

ほとんどのウェブサイトやアプリには、テンプレートや PHP ファイルにハードコードされたいくつかの UI メッセージを持ちます。CMS のコンテンツによって動的に定義されていないため、それらは「静的メッセージ」と呼ばれます。

多言語のサイトやアプリを構築している場合、CMS 主導のコンテンツのようにそれらのメッセージも翻訳可能にする必要があるでしょう。

そのため、Craft は Yii の[メッセージ翻訳](https://www.yiiframework.com/doc/guide/1.1/en/topics.i18n#message-translation)機能を採用し、フロントエンドのメッセージ向けの `site` という特別な翻訳カテゴリを事前に定義しています。

## メッセージの準備

最初のステップは、すべての静的メッセージをトランスレータを通して実行することです。テンプレートを操作している場合、[translate](dev/filters.md#translate-or-t) フィルタ（`|t`）を使用します。PHP コードを操作している場合、[Craft::t()](api:yii\BaseYii::t()) を使用します。

::: code

```twig
{# old #}
<a href="/contact">Contact us</a>

{# new #}
<a href="/contact">{{ 'Contact us'|t }}</a>
```

```php
// old
$label = 'Contact us';

// new
$label = Craft::t('site', 'Contact us');
```

:::

## 翻訳の提供

翻訳のためのメッセージを準備したら、実際の翻訳を提供する必要があります。

そのために、プロジェクトのベースディレクトリに `translations/` と呼ばれる新しいフォルダを作成し、その中に対象言語の ID を名前とした新しいフォルダを作成します。その中に、`site.php` というファイルを作成します。

例えば、サイトをドイツ語に翻訳する場合、プロジェクトのディレクトリ構造は次のようになります。

```
my-project.test/
├── config/
├── ...
└── translations/
    └── de/
        └── site.php
```

次に、テキストエディタで `site.php` を開き、ソースメッセージを翻訳メッセージにマップする配列を返すようにします。

```php
<?php

return [
    'Contact us' => 'Kontaktiere uns',
];
```

これで、ドイツ語サイトのメッセージ翻訳を処理する際、「Contact us」が「Kontaktiere uns」に置換されます。

