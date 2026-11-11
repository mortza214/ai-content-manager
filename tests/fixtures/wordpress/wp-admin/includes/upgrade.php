<?php
/** Minimal dbDelta recorder for migration contract tests; this is NOT WordPress dbDelta. */
function dbDelta( $sql ) {
    $GLOBALS['migration_statements'][] = $sql;
    if ( ! preg_match( '/CREATE TABLE (\w+) \((.*)\)\s*.*ENGINE=InnoDB;/s', $sql, $m ) ) { throw new RuntimeException( 'Unexpected migration statement' ); }
    $columns = array(); $indexes = array();
    foreach ( explode( "\n", $m[2] ) as $line ) {
        $line = trim( $line, " \r\t," );
        if ( preg_match( '/^(?:(UNIQUE) )?(PRIMARY KEY|KEY)\s+(?:(\w+)\s*)?\(([^)]+)\)/', $line, $key ) ) {
            $name = 'PRIMARY KEY' === $key[2] ? 'PRIMARY' : $key[3]; $indexes[$name] = array();
            foreach ( explode( ',', $key[4] ) as $i => $column ) { $indexes[$name][] = array( 'Seq_in_index' => $i + 1, 'Column_name' => trim( $column ), 'Non_unique' => 'PRIMARY' === $name || ! empty( $key[1] ) ? 0 : 1 ); }
        } elseif ( preg_match( '/^(\w+)\s+/', $line, $column ) ) { $columns[] = $column[1]; }
    }
    $GLOBALS['wpdb']->schema[$m[1]] = array( 'columns' => $columns, 'indexes' => $indexes );
}
