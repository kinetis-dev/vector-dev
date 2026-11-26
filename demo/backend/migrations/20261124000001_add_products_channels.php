<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// The sales channels a product is offered on: a multiple select's list of
// option values, held in a JSON column. products.attributes is JSON already.
return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE products ADD COLUMN channels JSON NULL AFTER attributes');
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE products DROP COLUMN channels');
    }
};
