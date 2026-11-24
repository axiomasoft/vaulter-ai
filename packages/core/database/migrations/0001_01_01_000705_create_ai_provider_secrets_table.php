<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Container::getInstance()->make(OfficeDatabase::class)->connection()->getName();
    }

    public function up(): void
    {
        $db = Container::getInstance()->make(OfficeDatabase::class);
        $conn = $db->connection();
        $grammar = $conn->getQueryGrammar();
        $table = $grammar->wrapTable($db->table('ai_provider_secrets'));
        $prefix = $db->table('');
        $keyVersionIdx = $grammar->wrap($prefix.'ai_provider_secrets_key_version_idx');
        $tenantIdx = $grammar->wrap($prefix.'ai_provider_secrets_tenant_idx');

        if ($conn->getDriverName() === 'pgsql') {
            $conn->statement(<<<SQL
                CREATE TABLE {$table} (
                    secret_id uuid PRIMARY KEY DEFAULT uuidv7(),
                    tenant_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(tenant_id) BETWEEN 1 AND 255),
                    connection varchar(128) COLLATE "C" NOT NULL CHECK (octet_length(connection) BETWEEN 1 AND 128),
                    driver varchar(64) NOT NULL CHECK (driver <> ''),
                    model varchar(255),
                    base_url varchar(1024),
                    hint varchar(32) NOT NULL CHECK (hint <> ''),
                    key_version integer NOT NULL CHECK (key_version >= 1),
                    key_id varchar(128) NOT NULL CHECK (key_id <> ''),
                    encrypted_secret bytea NOT NULL CHECK (octet_length(encrypted_secret) > 0),
                    rotated_at timestamptz,
                    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (tenant_id, connection)
                )
                SQL);
        } else {
            $conn->statement(<<<SQL
                CREATE TABLE {$table} (
                    secret_id text PRIMARY KEY,
                    tenant_id text NOT NULL CHECK (length(CAST(tenant_id AS BLOB)) BETWEEN 1 AND 255),
                    connection text NOT NULL CHECK (length(CAST(connection AS BLOB)) BETWEEN 1 AND 128),
                    driver text NOT NULL CHECK (driver <> ''),
                    model text,
                    base_url text,
                    hint text NOT NULL CHECK (hint <> ''),
                    key_version integer NOT NULL CHECK (key_version >= 1),
                    key_id text NOT NULL CHECK (key_id <> ''),
                    encrypted_secret blob NOT NULL CHECK (length(CAST(encrypted_secret AS BLOB)) > 0),
                    rotated_at text,
                    created_at text NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at text NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (tenant_id, connection)
                )
                SQL);
        }

        $conn->statement("CREATE INDEX {$keyVersionIdx} ON {$table} (key_version)");
        $conn->statement("CREATE INDEX {$tenantIdx} ON {$table} (tenant_id)");
    }

    public function down(): void
    {
        $db = Container::getInstance()->make(OfficeDatabase::class);
        $conn = $db->connection();
        $table = $conn->getQueryGrammar()->wrapTable($db->table('ai_provider_secrets'));
        $conn->statement("DROP TABLE IF EXISTS {$table}");
    }
};
