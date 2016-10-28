<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\app\db;

use Craft;
use craft\app\db\mysql\QueryBuilder;
use craft\app\errors\DbConnectException;
use craft\app\events\DbBackupEvent;
use craft\app\helpers\ArrayHelper;
use craft\app\helpers\Io;
use craft\app\helpers\StringHelper;
use mikehaertl\shellcommand\Command as ShellCommand;
use yii\db\Exception as DbException;

/**
 * @inheritdoc
 *
 * @property QueryBuilder $queryBuilder The query builder for the current DB connection.
 * @method QueryBuilder getQueryBuilder() Returns the query builder for the current DB connection.
 * @method Command createCommand($sql = null, $params = []) Creates a command for execution.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class Connection extends \yii\db\Connection
{
    // Constants
    // =========================================================================

    /**
     * @event \yii\base\Event The event that is triggered before the backup is created.
     */
    const EVENT_BEFORE_CREATE_BACKUP = 'beforeCreateBackup';

    /**
     * @event DbBackupEvent The event that is triggered after the DB backup is created.
     */
    const EVENT_AFTER_CREATE_BACKUP = 'afterCreateBackup';

    const DRIVER_MYSQL = 'mysql';
    const DRIVER_PGSQL = 'pgsql';

    // Properties
    // =========================================================================

    /**
     * @var string the class used to create new database [[Command]] objects. If you want to extend the [[Command]] class,
     * you may configure this property to use your extended version of the class.
     * @see   createCommand
     * @since 2.0.7
     */
    public $commandClass = \craft\app\db\Command::class;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @throws DbConnectException if there are any issues
     */
    public function open()
    {
        try {
            parent::open();
        } catch (DbException $e) {
            Craft::error($e->getMessage(), __METHOD__);

            // TODO: Multi-db driver check.
            if (!extension_loaded('pdo')) {
                throw new DbConnectException(Craft::t('app', 'Craft CMS requires the PDO extension to operate.'));
            } else if (!extension_loaded('pdo_mysql')) {
                throw new DbConnectException(Craft::t('app', 'Craft CMS requires the PDO_MYSQL driver to operate.'));
            } else {
                Craft::error($e->getMessage(), __METHOD__);
                throw new DbConnectException(Craft::t('app', 'Craft CMS can’t connect to the database with the credentials in craft/config/db.php.'));
            }
        } catch (\Exception $e) {
            Craft::error($e->getMessage(), __METHOD__);
            throw new DbConnectException(Craft::t('app', 'Craft CMS can’t connect to the database with the credentials in craft/config/db.php.'));
        }
    }

    /**
     * Performs a backup operation. If a `backupCommand` config setting has been set, will execute it. If not,
     * will execute the default database schema specific backup defined in `getDefaultBackupCommand()`, which uses
     * `pg_dump` for PostgreSQL and `mysqldump` for MySQL.
     *
     * @param array|null $ignoreDataTables If set to an empty array, a full database backup will be performed. If set
     *                                     to an array of database table names, they will get merged with the default
     *                                     list of table names whose data is to be ignored during a database backup.
     *
     * @return boolean|string The file path to the database backup, or false if something went wrong.
     */
    public function backup($ignoreDataTables = null)
    {
        $defaultIgnoreDataTables = [
            'assetindexdata',
            'assettransformindex',
            'sessions',
            'templatecaches',
            'templatecachequeries',
            'templatecacheelements'
        ];

        // If it's an empty array, we want every table.
        if ($ignoreDataTables !== []) {
            $ignoreDataTables = $ignoreDataTables ?: [];
            $ignoreDataTables = array_merge($defaultIgnoreDataTables, $ignoreDataTables);
        }

        // Normalize the ignored table names if there is a table prefix set.
        if (($tablePrefix = Craft::$app->getConfig()->getDbTablePrefix()) !== '' ) {
            foreach ($ignoreDataTables as $key => $tableName) {
                $ignoreDataTables[$key] = $tablePrefix.'_'.$tableName;
            }
        }

        $currentVersion = 'v'.Craft::$app->version.'.'.Craft::$app->build;
        $siteName = Io::cleanFilename($this->_getFixedSiteName(), true);
        $filename = ($siteName ? $siteName.'_' : '').gmdate('ymd_His').'_'.$currentVersion.'.sql';
        $filePath = Craft::$app->getPath()->getDbBackupPath().'/'.StringHelper::toLowerCase($filename);

        $command = new ShellCommand();

        // If we don't have proc_open, maybe we've got exec
        if (!function_exists('proc_open') && function_exists('exec')) {
            $command->useExec = true;
        }

        $config = Craft::$app->getConfig();
        $port = $config->getDbPort();
        $server = $config->get('server', Config::CATEGORY_DB);
        $user = $config->get('user', Config::CATEGORY_DB);
        $database = $config->get('database', Config::CATEGORY_DB);
        $schema = $config->get('schema', Config::CATEGORY_DB);

        // See if they are using their own backupCommand.
        if (($backupCommand = $config->get('backupCommand'))) {

            // Swap out any tokens
            $backupCommand = preg_replace('/\{filePath\}/', $filePath, $backupCommand);
            $backupCommand = preg_replace('/\{port\}/', $port, $backupCommand);
            $backupCommand = preg_replace('/\{server\}/', $server, $backupCommand);
            $backupCommand = preg_replace('/\{user\}/', $user, $backupCommand);
            $backupCommand = preg_replace('/\{database\}/', $database, $backupCommand);
            $backupCommand = preg_replace('/\{schema\}/', $schema, $backupCommand);

            $command->setCommand($backupCommand);
        } else {
            // Go with Craft's default.
            $command = $this->getSchema()->getDefaultBackupCommand($command, $filePath, $ignoreDataTables);
        }

        // Fire a 'beforeCreateBackup' event
        $this->trigger(self::EVENT_BEFORE_CREATE_BACKUP);

        if ($command->execute()) {
            // Fire an 'afterCreateBackup' event
            $this->trigger(self::EVENT_AFTER_CREATE_BACKUP,
                new DbBackupEvent(['filePath' => $filePath])
            );

            return $filePath;
        } else {
            $error = $command->getError();
            $exitCode = $command->getExitCode();

            Craft::error('Could not back up database. Error: '.$error.'. Exit Code:'.$exitCode, __METHOD__);
        }

        return false;
    }

    /**
     * Restores a database at the given file path.
     *
     * @param string $filePath The path of the database backup to restore.
     *
     * @return bool Whether the restore was successful or not.
     */
    public function restore($filePath)
    {
        if ($this->getSchema()->restore($filePath)) {
            return true;
        }

        return false;
    }

    /**
     * @param $name
     *
     * @return string
     */
    public function quoteDatabaseName($name)
    {
        return $this->getSchema()->quoteTableName($name);
    }

    /**
     * Returns whether a table exists.
     *
     * @param string       $table
     * @param boolean|null $refresh
     *
     * @return boolean
     */
    public function tableExists($table, $refresh = null)
    {
        // Default to refreshing the tables if Craft isn't installed yet
        if ($refresh || ($refresh === null && !Craft::$app->getIsInstalled())) {
            $this->getSchema()->refresh();
        }

        $table = $this->getSchema()->getRawTableName($table);

        return in_array($table, $this->getSchema()->getTableNames());
    }

    /**
     * Checks if a column exists in a table.
     *
     * @param string       $table
     * @param string       $column
     * @param boolean|null $refresh
     *
     * @return boolean
     */
    public function columnExists($table, $column, $refresh = null)
    {
        // Default to refreshing the tables if Craft isn't installed yet
        if ($refresh || ($refresh === null && !Craft::$app->getIsInstalled())) {
            $this->getSchema()->refresh();
        }

        $table = $this->getTableSchema('{{'.$table.'}}');

        if ($table) {
            if (($column = $table->getColumn($column)) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns a foreign key name based on the table and column names.
     *
     * @param string       $table
     * @param string|array $columns
     *
     * @return string
     */
    public function getForeignKeyName($table, $columns)
    {
        $table = $this->_getTableNameWithoutPrefix($table);
        $columns = ArrayHelper::toArray($columns);
        $name = $this->tablePrefix.$table.'_'.implode('_', $columns).'_fk';

        return $this->trimObjectName($name);
    }

    /**
     * Returns an index name based on the table, column names, and whether
     * it should be unique.
     *
     * @param string       $table
     * @param string|array $columns
     * @param boolean      $unique
     *
     * @return string
     */
    public function getIndexName($table, $columns, $unique = false)
    {
        $table = $this->_getTableNameWithoutPrefix($table);
        $columns = ArrayHelper::toArray($columns);
        $name = $this->tablePrefix.$table.'_'.implode('_',
                $columns).($unique ? '_unq' : '').'_idx';

        return $this->trimObjectName($name);
    }

    /**
     * Returns a primary key name based on the table and column names.
     *
     * @param string       $table
     * @param string|array $columns
     *
     * @return string
     */
    public function getPrimaryKeyName($table, $columns)
    {
        $table = $this->_getTableNameWithoutPrefix($table);
        $columns = ArrayHelper::toArray($columns);
        $name = $this->tablePrefix.$table.'_'.implode('_', $columns).'_pk';

        return $this->trimObjectName($name);
    }

    /**
     * Ensures that an object name is within the schema's limit.
     *
     * @param string $name
     *
     * @return string
     */
    public function trimObjectName($name)
    {
        $schema = $this->getSchema();

        if (!isset($schema->maxObjectNameLength)) {
            return $name;
        }

        $name = trim($name, '_');
        $nameLength = StringHelper::length($name);

        if ($nameLength > $schema->maxObjectNameLength) {
            $parts = array_filter(explode('_', $name));
            $totalParts = count($parts);
            $totalLetters = $nameLength - ($totalParts - 1);
            $maxLetters = $schema->maxObjectNameLength - ($totalParts - 1);

            // Consecutive underscores could have put this name over the top
            if ($totalLetters > $maxLetters) {
                foreach ($parts as $i => $part) {
                    $newLength = round($maxLetters * StringHelper::length($part) / $totalLetters);
                    $parts[$i] = mb_substr($part, 0, $newLength);
                }
            }

            $name = implode('_', $parts);

            // Just to be safe
            if (StringHelper::length($name) > $schema->maxObjectNameLength) {
                $name = mb_substr($name, 0, $schema->maxObjectNameLength);
            }
        }

        return $name;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a table name without the table prefix
     *
     * @param string $table
     *
     * @return string
     */
    private function _getTableNameWithoutPrefix($table)
    {
        $table = $this->getSchema()->getRawTableName($table);

        if ($this->tablePrefix) {
            $prefixLength = strlen($this->tablePrefix);

            if (strncmp($table, $this->tablePrefix, $prefixLength) === 0) {
                $table = substr($table, $prefixLength);
            }
        }

        return $table;
    }

    /**
     * TODO: remove this method after the next breakpoint and just use getPrimarySite() directly.
     *
     * @return string
     */
    private function _getFixedSiteName() {
        if (version_compare(Craft::$app->getInfo('version'), '3.0', '<') || Craft::$app->getInfo('build') < 2933) {
            return (new Query())
                ->select(['siteName'])
                ->from(['{{%info}}'])
                ->column()[0];
        } else {
            return Craft::$app->getSites()->getPrimarySite()->name;
        }
    }
}
