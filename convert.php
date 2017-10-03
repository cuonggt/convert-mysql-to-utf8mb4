<?php

$host = '127.0.0.1';
$database = 'testdb';
$username = 'root';
$password = '';
$encoding = 'utf8mb4';
$collation = 'utf8mb4_unicode_ci';
$row_format = 'DYNAMIC';

$exclude_tables = [
    //
];

try {
    $dsn = "mysql:dbname={$database};host={$host}";

    $db = new PDO($dsn, $username, $password);
} catch (PDOException $e) {
    echo 'Connection failed: ' . $e->getMessage();
    die();
}

try {
    $db->beginTransaction();

    echo "Working on database {$database} ...\n";

    // Alter the database to specific encoding
    $db->exec("ALTER DATABASE {$database} CHARACTER SET = {$encoding} COLLATE = {$collation}");
    $db->commit();

    $db->beginTransaction();

    // Alter table's row format and character set
    $tables = array_filter($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), function ($table) use ($exclude_tables) {
        return ! in_array($table, $exclude_tables);
    });

    foreach ($tables as $table) {
        echo "Working on table {$table} ...\n";

        $db->exec("ALTER TABLE {$table} ROW_FORMAT={$row_format}");
        $db->exec("ALTER TABLE {$table} CONVERT TO CHARACTER SET {$encoding} COLLATE {$collation}");
    }

    // Alter all the columns of all the tables to specific encoding and collation
    $string_columns = array_flatten(build_db_string_metadata($db, $tables));

    foreach ($string_columns as $column) {
        echo "Working on column: " . json_encode($column) . "\n";

        $db->exec("ALTER TABLE {$column['table']} CHANGE {$column['column_name']} {$column['column_name']} {$column['sql_type']} CHARACTER SET {$encoding} COLLATE {$collation}");
    }

    foreach ($tables as $table) {
        echo "Reparing and optimizing table {$table} ...\n";

        $db->exec("REPAIR TABLE {$table}");
        $db->exec("OPTIMIZE TABLE {$table}");
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();

    echo "Failed: " . $e->getMessage();
}

function build_db_string_metadata($db, $tables) {
    $text_fields = [];

    foreach ($tables as $table) {
        $text_fields[$table] = [];

        $columns = $db->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_CLASS);

        $indexes = $db->query("SHOW INDEXES FROM {$table}")->fetchAll(PDO::FETCH_CLASS);

        foreach ($columns as $column) {
            if (! preg_match('/(char|text)/', strtolower($column->Type), $matches)) {
                continue;
            }

            $has_index = false;

            foreach ($indexes as $index) {
                if (strpos($index->Column_name, $column->Field) !== false) {
                    $has_index = true;
                    break;
                }
            }

            $text_fields[$table][] = [
                'table' => $table,
                'column_name' => $column->Field,
                'sql_type' => $column->Type,
                'has_index' => $has_index
            ];
        }
    }

    return $text_fields;
}

function array_flatten($array) {
    return array_reduce($array, function ($result, $item) {
        return array_merge($result, array_values($item));
    }, []);
}
