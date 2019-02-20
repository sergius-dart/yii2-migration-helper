<?php
namespace sergiusdart\db;

use Yii;
use yii\db\ColumnSchemaBuilder;
use yii\db\Migration as OldMigration;
use yii\base\InvalidArgumentException;

class ForeignColumnBuilder extends ColumnSchemaBuilder
{
    public $migration = null;
    public $table;
    public $columns;
    public $refTable;
    public $refColumns;

    public $ondelete=null;
    public $onupdate=null;

    public function loadTypes($table, $column)
    {
        $this->table = $table;
        $this->columns = $column;
    }

    public function getFkName()
    {
        return join('_',[ 'fk', $this->table, $this->columns, $this->refTable, $this->refColumns ]);
    }

    static public function fromColumn( ColumnSchemaBuilder $column, $config=[] )
    {
        $type = 'integer'; //default - value
        switch ($column->type)
        {
        case self::CATEGORY_PK:
            $type='integer'; //only postgres - lol.
        }
        return new ForeignColumnBuilder($type,$column->length, $column->db, $config);
    }
}

class ForeignKeyConstrainBuilder extends \yii\base\BaseObject
{
    public $column;
    public function apply($migration)
    {
        $column = $this->column;
        $migration->addForeignKey( 
            $column->fkName, 
            $column->table,
            $column->columns, 
            $column->refTable, 
            $column->refColumns, 
            $column->ondelete, 
            $column->onupdate
        );
    }
    public function drop($migration)
    {
        $column = $this->column;
        $migration->dropForeignKey($column->fkName,$column->table);
    }
}


class Migration extends OldMigration
{
    const FOREIGN_CASCADE='CASCADE';
    const FOREIGN_RESTRICT='RESTRICT';
    const FOREIGN_SET_NULL='SET NULL';
    const FOREIGN_SET_DEFAULT='SET DEFAULT';
    /**
     * collect all tables tableName=>columns
     * Modify from addTable/removeTable 
     */
    protected $tables = [];
    /**
     * collect all constrains. Filled by makeForeignKey(TODO)
     */
    protected $constraints = [];

    protected function foreignKey( $table, $column_name, $config=[] )
    {
        if ( !isset($this->tables[ $table ]))
            throw new InvalidArgumentException('Not found table - circular?');
        $_table = $this->tables[ $table ];

        if ( !isset($_table[ $column_name ]))
            throw new InvalidArgumentException('Not found column '.$column_name);
        
        $_column = $_table[ $column_name ];
        $config['migration'] = $this;
        $config['refTable']=$table;
        $config['refColumns']=$column_name;

        $column = ForeignColumnBuilder::fromColumn( $_column, $config );

        $this->addFk($column);

        return $column;
    }

    protected function addTable($table_name, $columns=[] )
    {
        if ( isset( $this->tables[ $table_name] ) )
            throw new InvalidArgumentException('Duplicate table : '.$table_name);
        
        $this->tables[$table_name] = $columns;

        return $this;
    }

    /**
     * Use addTable from this functions - add all tables to need
     */
    protected function prepareTables(){}

    /**
     * If need alter column - make !
     */
    protected function prepareColumns(){}

    /**
     * Need toooo more constraints? Just do it!
     */
    protected function prepareConstraints(){}

    protected function prepareAll()
    {
        $this->prepareTables();
        $this->prepareColumns();
        $this->prepareConstraints();
        foreach($this->tables as $table_name=>$table)
        {
            foreach($table as $column_name=>$column)
            {
                if ( method_exists( $column, 'loadTypes') && is_callable( [$column, 'loadTypes']))
                    $column->loadTypes($table_name, $column_name);
            }
        }
    }

    public function safeUp()
    {
       
        $this->prepareAll();

        //TODO?
        
        $this->applyTables();
        $this->applyColumns();
        $this->applyConstraint();
    }

    /**
     * Create all tables from $tables var.
     */
    protected function applyTables()
    {
        foreach($this->tables as $table_name=>$columns )
        {
            $this->createTable($table_name,$columns);
        }
    }

    protected function applyColumns()
    {

    }

    protected function applyConstraint()
    {
        foreach( $this->constraints as $constraint)
        {
            $constraint->apply($this);
        }
    }

    public function addFk( ForeignColumnBuilder $column)
    {
        $this->constraints []= new ForeignKeyConstrainBuilder(['column'=>$column]);
    }

    public function safeDown()
    {
        $this->prepareAll();
        
        try{
            $this->dropConstraints();
            $this->dropColumns();
            $this->dropTables();
        } catch( \Exception $E )
        {
            echo $E->getTraceAsString();
            return false;
        }
        return true;
    }

    protected function dropConstraints()
    {
        foreach( $this->constraints as $constraint)
            if ( method_exists( $constraint, 'drop') && is_callable( [$constraint, 'drop']))
                $constraint->drop($this);
    }

    protected function dropColumns()
    {

    }

    protected function dropTables()
    {
        foreach($this->tables as $table_name=>$columns )
            $this->dropTable($table_name);
    }
}