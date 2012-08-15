<?php
/**
 * PHP to MySQL connectivity
 *
 * @package DBSteward
 * @subpackage mysql5
 * @license http://www.opensource.org/licenses/bsd-license.php Simplified BSD License
 * @author Nicholas J Kiraly <kiraly.nicholas@gmail.com>
 */

class mysql5_db {

  protected $pdo;
  protected $dbname;

  public static function connect($host, $port, $database, $user, $password) {
    // mysql puts all metadata in the information_schema database, but assigns objects to their respective DBs
    return new mysql5_db(new PDO("mysql:host=$host;port=$port;dbname=information_schema", $user, $password), $database);
  }

  public function __construct($pdo, $dbname) {
    $this->pdo = $pdo;
    $this->dbname = $dbname;

    // Make sure we throw exceptions when bad stuff happens
    $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
  }

  public function get_tables() {
    // table_schema is database name. why? because mysql, that's why.
    $stmt = $this->pdo->prepare("SELECT table_schema, table_name, engine,
                                        table_rows, auto_increment, table_comment
                                 FROM tables
                                 WHERE table_type = 'base table'
                                   AND table_schema = ?");

    $stmt->execute(array($this->dbname));
    return $stmt->fetchAll(PDO::FETCH_OBJ);
  }

  public function get_columns($db_table) {
    static $stmt;
    if (!$stmt) {
      $stmt = $this->pdo->prepare("SELECT table_name, column_name, column_default,
                                          is_nullable, data_type, character_maximum_length, numeric_precision,
                                          numeric_scale, column_type, column_key, extra, privileges
                                   FROM columns
                                   WHERE table_name = ?
                                     AND table_schema = ?
                                   ORDER BY ordinal_position ASC");
    }

    $stmt->execute(array($db_table->table_name, $this->dbname));
    return $stmt->fetchAll(PDO::FETCH_OBJ);
  }

  public function get_indices($db_table) {
    // only show those indexes which are not keys/constraints, unless it's a unique constraint
    static $stmt;
    if (!$stmt) {
      $stmt = $this->pdo->prepare("SELECT statistics.table_name,
                                          GROUP_CONCAT(statistics.column_name ORDER BY seq_in_index) AS columns,
                                          NOT statistics.non_unique AS 'unique', statistics.index_name,
                                          statistics.nullable, statistics.comment, statistics.index_type
                                   FROM statistics
                                     LEFT OUTER JOIN key_column_usage USING (table_schema, table_name, column_name)
                                     LEFT OUTER JOIN table_constraints USING (table_schema, table_name, constraint_name)
                                   WHERE statistics.table_schema = ?
                                     AND statistics.table_name = ?
                                     AND (key_column_usage.constraint_name IS NULL 
                                          OR table_constraints.constraint_type = 'UNIQUE')
                                   GROUP BY index_name");
    }

    $stmt->execute(array($this->dbname, $db_table->table_name));
    $indices = $stmt->fetchAll(PDO::FETCH_OBJ);

    foreach ($indices as &$idx) {
      // massage the output
      $idx->columns = explode(',',$idx->columns);
    }

    return $indices;
  }

  public function get_constraints($db_table) {
    static $stmt;
    if (!$stmt) {
      $stmt = $this->pdo->prepare("SELECT GROUP_CONCAT(column_name ORDER BY position_in_unique_constraint, seq_in_index) AS columns,
                                          statistics.table_name, table_constraints.constraint_name, index_name, constraint_type, key_column_usage.referenced_table_name,
                                          GROUP_CONCAT(referenced_column_name ORDER BY position_in_unique_constraint) as referenced_columns,
                                          update_rule, delete_rule
                                    FROM statistics
                                     INNER JOIN key_column_usage USING (table_schema, table_name, column_name)
                                     INNER JOIN table_constraints USING (table_schema, table_name, constraint_name)
                                     LEFT OUTER JOIN referential_constraints
                                             ON referential_constraints.constraint_schema = statistics.table_schema
                                            AND referential_constraints.constraint_name = table_constraints.constraint_name
                                            AND referential_constraints.table_name = statistics.table_name
                                    WHERE statistics.table_schema = ?
                                     AND statistics.table_name = ?
                                     AND (table_constraints.constraint_type != 'UNIQUE')
                                    GROUP BY index_name;");
    }

    $stmt->execute(array($this->dbname, $db_table->table_name));
    $constraints = $stmt->fetchAll(PDO::FETCH_OBJ);

    foreach ($constraints as &$constraint) {
      // massage the output
      $constraint->columns = explode(',',$constraint->columns);

      if ($constraint->referenced_columns) {
        $constraint->referenced_columns = explode(',',$constraint->referenced_columns);
      }
    }

    return $constraints;
  }

  public function get_functions() {
    static $fn_stmt, $param_stmt;
    if (!$fn_stmt) {
      $fn_stmt = $this->pdo->prepare("SELECT routine_name, data_type, character_maximum_length, numeric_precision,
                                          numeric_scale, routine_definition, is_deterministic, security_type, definer, sql_data_access, dtd_identifier
                                   FROM routines
                                   WHERE routine_type = 'FUNCTION'
                                     AND routine_schema = ?");
      $param_stmt = $this->pdo->prepare("SELECT parameter_mode, parameter_name, data_type, character_maximum_length,
                                                numeric_precision, numeric_scale, dtd_identifier
                                         FROM parameters
                                         WHERE specific_schema = ?
                                           AND specific_name = ?
                                           AND parameter_name IS NOT NULL
                                         ORDER BY ordinal_position ASC");
    }
    $fn_stmt->execute(array($this->dbname));

    $functions = array();
    foreach ($fn_stmt->fetchAll(PDO::FETCH_OBJ) as $fn) {
      $param_stmt->execute(array($this->dbname, $fn->routine_name));
      $fn->parameters = $param_stmt->fetchAll(PDO::FETCH_OBJ);
      $functions[] = $fn;
    }

    return $functions;
  }

  public function get_triggers() {
    static $stmt;
    if (!$stmt) {
      $stmt = $this->pdo->prepare("SELECT trigger_name AS name, event_manipulation AS event, 
                                          event_object_table AS 'table', action_statement AS function,
                                          action_orientation AS forEach, action_timing AS 'when'
                                   FROM triggers
                                   WHERE trigger_schema = ?");
    }
    $stmt->execute(array($this->dbname));
    return $stmt->fetchAll(PDO::FETCH_OBJ);
  }

  public function get_views() {
    static $stmt;
    if (!$stmt) {
      $stmt = $this->pdo->prepare("SELECT table_name AS view_name, view_definition AS view_query,
                                          definer, 'DEFINER' AS security_type
                                   FROM views
                                   WHERE table_schema = ?");
    }
    $stmt->execute(array($this->dbname));
    return $stmt->fetchAll(PDO::FETCH_OBJ);
  }

  public function get_type_string($db_object) {
    $type = $db_object->data_type;

    if ( $db_object->character_maximum_length ) {
      if ( stripos($db_object->data_type, 'text') === FALSE && stripos($db_object->data_type, 'enum') === FALSE ) {
        $type .= '(' . $db_object->character_maximum_length . ')';
      }
    }
    elseif ( $db_object->numeric_scale !== NULL ) {
      $type .= '(' . $db_object->numeric_scale . ',' . $db_object->numeric_precision . ')';
    }

    return $type;
  }

  public function parse_enum_values($enum) {
    // test to match enum('word'[, ...])
    if ( preg_match('/enum\((\'\w+\'(?:,\'\w+\')*)\)/i', $enum, $matches) == 1 ) {
      return explode(',', str_replace("'",'',$matches[1]));
    }
    else {
      throw new Exception("Invalid enum declaration: $enum");
    }
  }
}

// test driver
$db = mysql5_db::connect('localhost','1433','test','austin.hyde','');

foreach ($db->get_tables() as $table) {
  echo "constraints for $table->table_name\n";
  print_r($db->get_constraints($table));
}