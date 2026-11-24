<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// The order of an order's lines, which the lines repeater writes when its
// rows are dragged into a new order. Existing lines keep the order they
// were created in: their position is their rank by id within the order.
return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE order_lines ADD COLUMN position INT NOT NULL DEFAULT 0 AFTER unit_price_cents');
        $db->execute(<<<'SQL'
            UPDATE order_lines
            JOIN (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY order_id ORDER BY id) - 1 AS ranked
                FROM order_lines
            ) AS ranks ON ranks.id = order_lines.id
            SET order_lines.position = ranks.ranked
            SQL);
        $db->execute('CREATE INDEX order_lines_order_id_position_index ON order_lines (order_id, position)');
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP INDEX order_lines_order_id_position_index ON order_lines');
        $db->execute('ALTER TABLE order_lines DROP COLUMN position');
    }
};
