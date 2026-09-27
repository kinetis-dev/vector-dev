<?php

declare(strict_types=1);

namespace App\Http;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Hidden;
use Kinetis\Http\Middleware\SecurityHeadersMiddleware;
use Kinetis\Http\Responses\HtmlResponse;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\RichText\Document;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The shop's own product page — an application view, not the admin —
 * showing how an application renders a rich-text value it stores:
 * Document::toHtml() turns the editor's JSON document into escaped HTML
 * through the same schema. Images are private uploads, so this public
 * page leaves them out (a shop with public product images would map each
 * storage key to its own URL instead).
 */
#[Hidden]
final readonly class ProductPageController
{
    public function __construct(
        private MysqlLink $db,
        private Document $document,
    ) {}

    #[Get('/shop/products/{id}')]
    public function show(int $id, ServerRequestInterface $request): ResponseInterface
    {
        $product = (new Query($this->db))->table('products')
            ->select('name', 'sku', 'price_cents', 'currency', 'description')
            ->where('id', '=', $id)
            ->where('deleted_at', '=', null)
            ->first();

        if ($product === null) {
            return HtmlResponse::create('<!doctype html><title>Not found</title><p>No such product.</p>', 404);
        }

        $nonce = $request->getAttribute(SecurityHeadersMiddleware::NONCE_ATTRIBUTE);
        $nonceAttribute = is_string($nonce) ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $price = number_format((int) $product['price_cents'] / 100, 2) . ' ' . $e($product['currency']);
        $description = is_string($product['description'])
            ? $this->document->toHtml($product['description'], Document::DEFAULT_FEATURES, static fn (string $key): ?string => null)
            : '';

        return HtmlResponse::create(<<<HTML
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>{$e($product['name'])} · Vector Demo shop</title>
                <style{$nonceAttribute}>
                    body { font-family: system-ui, sans-serif; max-width: 42rem; margin: 3rem auto; padding: 0 1rem; line-height: 1.5; }
                    .sku { color: #52585f; }
                    table { border-collapse: collapse; } td, th { border: 1px solid #cfd8dc; padding: .25rem .5rem; }
                </style>
            </head>
            <body>
                <h1>{$e($product['name'])}</h1>
                <p class="sku">{$e($product['sku'])} · {$price}</p>
                <article class="description">{$description}</article>
            </body>
            </html>
            HTML);
    }
}
