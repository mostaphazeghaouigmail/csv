<?php

namespace LeagueTest\Csv;

use Generator;
use League\Csv\Exception\OutOfRangeException;
use League\Csv\Exception\RuntimeException;
use League\Csv\Reader;
use League\Csv\Statement;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use SplTempFileObject;

/**
 * @group reader
 * @coversDefaultClass League\Csv\ResultSet
 */
class ResultSetTest extends TestCase
{
    private $csv;

    private $stmt;

    private $expected = [
        ['john', 'doe', 'john.doe@example.com'],
        ['jane', 'doe', 'jane.doe@example.com'],
    ];

    public function setUp()
    {
        $tmp = new SplTempFileObject();
        foreach ($this->expected as $row) {
            $tmp->fputcsv($row);
        }

        $this->csv = Reader::createFromFileObject($tmp);
        $this->stmt = new Statement();
    }

    public function tearDown()
    {
        $this->csv = null;
        $this->stmt = null;
    }

    /**
     * @covers League\Csv\Statement::process
     * @covers League\Csv\Statement::limit
     * @covers ::getIterator
     */
    public function testSetLimit()
    {
        $this->assertCount(1, $this->stmt->limit(1)->process($this->csv));
    }

    /**
     * @covers League\Csv\Statement::offset
     */
    public function testSetOffsetThrowsException()
    {
        $this->expectException(OutOfRangeException::class);
        $this->stmt->offset(-1);
    }


    /**
     * @covers League\Csv\Statement::process
     * @covers League\Csv\Statement::buildOrderBy
     * @covers ::count
     * @covers ::getIterator
     * @covers ::iteratorToGenerator
     */
    public function testCountable()
    {
        $records = $this->stmt->limit(1)->process($this->csv);
        $this->assertCount(1, $records);
        $this->assertInstanceOf(Generator::class, $records->getIterator());
    }

    /**
     * @covers League\Csv\Statement::limit
     * @covers League\Csv\Statement::offset
     */
    public function testStatementSameInstance()
    {
        $stmt_alt = $this->stmt->limit(-1)->offset(0);

        $this->assertSame($stmt_alt, $this->stmt);
    }

    /**
     * @covers League\Csv\Statement::limit
     * @covers League\Csv\Exception\OutOfRangeException
     */
    public function testSetLimitThrowException()
    {
        $this->expectException(OutOfRangeException::class);
        $this->stmt->limit(-4);
    }

    /**
     * @covers League\Csv\Statement::offset
     * @covers ::__construct
     */
    public function testSetOffset()
    {
        $this->assertContains(
            ['jane', 'doe', 'jane.doe@example.com'],
            $this->stmt->offset(1)->process($this->csv)
        );
    }

    /**
     * @covers League\Csv\Statement::limit
     * @covers League\Csv\Statement::offset
     * @covers League\Csv\Statement::process
     * @dataProvider intervalTest
     * @param int $offset
     * @param int $limit
     */
    public function testInterval(int $offset, int $limit)
    {
        $this->assertContains(
            ['jane', 'doe', 'jane.doe@example.com'],
            $this->stmt
                ->offset($offset)
                ->limit($limit)
                ->where(function (array $record): bool {
                    return true;
                })
                ->where(function (array $record): bool {
                    return !empty($record);
                })
                ->process($this->csv)
        );
    }

    public function intervalTest()
    {
        return [
            'tooHigh' => [1, 10],
            'normal' => [1, 1],
        ];
    }

    /**
     * @covers League\Csv\Statement::limit
     * @covers League\Csv\Statement::offset
     * @covers League\Csv\Statement::process
     * @covers League\Csv\Exception\OutOfRangeException
     */
    public function testIntervalThrowException()
    {
        $this->expectException(OutOfBoundsException::class);
        iterator_to_array($this->stmt
            ->offset(1)
            ->limit(0)
            ->process($this->csv));
    }

    /**
     * @covers League\Csv\Statement::where
     */
    public function testFilter()
    {
        $func = function ($row) {
            return !in_array('jane', $row);
        };

        $this->assertNotContains(
            ['jane', 'doe', 'jane.doe@example.com'],
            iterator_to_array($this->stmt->where($func)->process($this->csv), false)
        );
    }

    /**
     * @covers League\Csv\Statement::orderBy
     * @covers League\Csv\Statement::buildOrderBy
     */
    public function testOrderBy()
    {
        $func = function (array $rowA, array $rowB): int {
            return strcmp($rowA[0], $rowB[0]);
        };
        $this->assertSame(
            array_reverse($this->expected),
            iterator_to_array($this->stmt->orderBy($func)->process($this->csv), false)
        );
    }

    /**
     * @covers League\Csv\Statement::orderBy
     * @covers League\Csv\Statement::buildOrderBy
     */
    public function testOrderByWithEquity()
    {
        $func = function (array $rowA, array $rowB): int {
            return strlen($rowA[0]) <=> strlen($rowB[0]);
        };

        $this->assertSame(
            $this->expected,
            iterator_to_array($this->stmt->orderBy($func)->process($this->csv), false)
        );
    }

    /**
     * @covers ::fetchColumn
     * @covers ::getColumnIndex
     * @covers ::getColumnIndexByValue
     * @covers ::getColumnIndexByKey
     * @covers ::iteratorToGenerator
     * @covers ::__destruct
     * @covers League\Csv\Exception\RuntimeException
     * @covers League\Csv\MapIterator
     * @dataProvider invalidFieldNameProvider
     * @param int|string $field
     */
    public function testFetchColumnTriggersException($field)
    {
        $this->expectException(RuntimeException::class);
        $this->csv->setHeaderOffset(0);
        $res = $this->stmt->process($this->csv)->fetchColumn($field);
        iterator_to_array($res, false);
    }

    public function invalidFieldNameProvider()
    {
        return [
            'invalid integer offset' => [24],
            'unknown column name' => ['fooBar'],
        ];
    }

    /**
     * @covers ::fetchColumn
     * @covers ::getColumnIndexByKey
     * @covers ::iteratorToGenerator
     * @covers League\Csv\MapIterator
     * @covers League\Csv\Exception\OutOfRangeException
     *
     * @param int|string $field
     */
    public function testFetchColumnTriggersOutOfRangeException()
    {
        $this->expectException(OutOfRangeException::class);
        $this->csv->setHeaderOffset(0);
        $res = $this->stmt->process($this->csv)->fetchColumn(-1);
        iterator_to_array($res, false);
    }

    /**
     * @covers ::getRecords
     * @covers ::getIterator
     */
    public function testFetchAssocWithRowIndex()
    {
        $arr = [
            ['A', 'B', 'C'],
            [1, 2, 3],
            ['D', 'E', 'F'],
            [6, 7, 8],
        ];

        $tmp = new SplTempFileObject();
        foreach ($arr as $row) {
            $tmp->fputcsv($row);
        }

        $csv = Reader::createFromFileObject($tmp);
        $csv->setHeaderOffset(2);
        $this->assertContains(
            ['D' => '6', 'E' => '7', 'F' => '8'],
            iterator_to_array($this->stmt->process($csv), false)
        );
    }

    /**
     * @covers ::preserveRecordOffset
     * @covers ::isRecordOffsetPreserved
     * @covers ::iteratorToGenerator
     */
    public function testPreserveOffset()
    {
        $expected = ['parent name', 'parentA'];
        $source = Reader::BOM_UTF8.'"parent name","child name","title"
            "parentA","childA","titleA"';
        $csv = Reader::createFromString($source);
        $csv->setHeaderOffset(0);
        $expectedNoOffset = [
            0 => ['parent name' => 'parentA', 'child name' => 'childA', 'title' => 'titleA'],
        ];
        $expectedWithOffset = [
            1 => ['parent name' => 'parentA', 'child name' => 'childA', 'title' => 'titleA'],
        ];
        $records = $this->stmt->process($csv);
        $records->preserveRecordOffset(false);
        $this->assertFalse($records->isRecordOffsetPreserved());
        $this->assertSame($expectedNoOffset, iterator_to_array($records, false));
        $records->preserveRecordOffset(true);
        $this->assertTrue($records->isRecordOffsetPreserved());
        $this->assertSame($expectedWithOffset, iterator_to_array($records, true));
        foreach ($records as $offset => $record) {
            $this->assertInternalType('int', $offset);
        }
    }

    /**
     * @covers ::fetchColumn
     * @covers ::getColumnIndex
     * @covers ::getColumnIndexByValue
     * @covers ::getColumnIndexByKey
     * @covers League\Csv\MapIterator
     */
    public function testFetchColumnWithColumnname()
    {
        $source = Reader::BOM_UTF8.'"parent name","child name","title"
            "parentA","childA","titleA"';
        $csv = Reader::createFromString($source);
        $csv->setHeaderOffset(0);
        $this->assertContains('parentA', $this->stmt->process($csv)->fetchColumn('parent name'));
        $this->assertContains('parentA', $this->stmt->process($csv)->fetchColumn(0));
    }

    /**
     * @covers ::fetchColumn
     * @covers ::getColumnIndex
     * @covers ::getColumnIndexByValue
     * @covers ::getColumnIndexByKey
     * @covers League\Csv\MapIterator
     */
    public function testFetchColumn()
    {
        $this->assertContains('john', $this->stmt->process($this->csv)->fetchColumn(0));
        $this->assertContains('jane', $this->stmt->process($this->csv)->fetchColumn());
    }

    /**
     * @covers ::fetchColumn
     * @covers ::iteratorToGenerator
     * @covers ::getColumnIndex
     * @covers ::getColumnIndexByKey
     * @covers League\Csv\MapIterator
     */
    public function testFetchColumnInconsistentColumnCSV()
    {
        $raw = [
            ['john', 'doe'],
            ['lara', 'croft', 'lara.croft@example.com'],
        ];

        $file = new SplTempFileObject();
        foreach ($raw as $row) {
            $file->fputcsv($row);
        }
        $csv = Reader::createFromFileObject($file);
        $res = $this->stmt->process($csv)->fetchColumn(2);
        $this->assertCount(1, iterator_to_array($res));
    }

    /**
     * @covers ::fetchColumn
     * @covers ::getColumnIndex
     * @covers ::getColumnIndexByKey
     * @covers League\Csv\MapIterator
     */
    public function testFetchColumnEmptyCol()
    {
        $raw = [
            ['john', 'doe'],
            ['lara', 'croft'],
        ];

        $file = new SplTempFileObject();
        foreach ($raw as $row) {
            $file->fputcsv($row);
        }
        $csv = Reader::createFromFileObject($file);
        $res = $this->stmt->process($csv)->fetchColumn(2);
        $this->assertCount(0, iterator_to_array($res));
    }

    /**
     * @covers ::fetchOne
     */
    public function testfetchOne()
    {
        $this->assertSame($this->expected[0], $this->stmt->process($this->csv)->fetchOne(0));
        $this->assertSame($this->expected[1], $this->stmt->process($this->csv)->fetchOne(1));
        $this->assertSame([], $this->stmt->process($this->csv)->fetchOne(35));
    }

    /**
     * @covers ::fetchOne
     */
    public function testFetchOneTriggersException()
    {
        $this->expectException(OutOfRangeException::class);
        $this->stmt->process($this->csv)->fetchOne(-5);
    }

    /**
     * @covers ::fetchPairs
     * @covers ::getColumnIndex
     * @dataProvider fetchPairsDataProvider
     * @param int|string $key
     * @param int|string $value
     * @param array      $expected
     */
    public function testFetchPairsIteratorMode($key, $value, array $expected)
    {
        $iterator = $this->stmt->process($this->csv)->fetchPairs($key, $value);
        foreach ($iterator as $key => $value) {
            $res = current($expected);
            $this->assertSame($value, $res[$key]);
            next($expected);
        }
    }

    public function fetchPairsDataProvider()
    {
        return [
            'default values' => [
                'key' => 0,
                'value' => 1,
                'expected' => [
                    ['john' => 'doe'],
                    ['jane' => 'doe'],
                ],
            ],
            'changed key order' => [
                'key' => 1,
                'value' => 0,
                'expected' => [
                    ['doe' => 'john'],
                    ['doe' => 'jane'],
                ],
            ],
        ];
    }

    /**
     * @covers ::fetchPairs
     * @covers ::getColumnIndex
     */
    public function testFetchPairsWithInvalidOffset()
    {
        $this->assertCount(0, iterator_to_array($this->stmt->process($this->csv)->fetchPairs(10, 1), true));
    }

    /**
     * @covers ::fetchPairs
     * @covers ::getColumnIndex
     */
    public function testFetchPairsWithInvalidValue()
    {
        $res = $this->stmt->process($this->csv)->fetchPairs(0, 15);
        foreach ($res as $value) {
            $this->assertNull($value);
        }
    }

    /**
     * @covers ::getHeader
     */
    public function testGetHeader()
    {
        $expected = ['firstname', 'lastname', 'email'];
        $this->assertSame([], $this->stmt->process($this->csv)->getHeader());
        $this->assertSame($expected, $this->stmt->process($this->csv, $expected)->getHeader());
        $this->csv->setHeaderOffset(0);
        $this->assertSame($this->expected[0], $this->stmt->process($this->csv)->getHeader());
        $this->assertSame($expected, $this->stmt->process($this->csv, $expected)->getHeader());
    }

    /**
     * @covers ::getRecords
     * @covers ::getIterator
     * @covers ::iteratorToGenerator
     */
    public function testGetRecords()
    {
        $result = $this->stmt->process($this->csv);
        $this->assertEquals($result->getIterator(), $result->getRecords());
        $result->preserveRecordOffset(true);
        $this->assertEquals($result->getIterator(), $result->getRecords());
    }

    /**
     * @covers ::jsonSerialize
     */
    public function testJsonSerialize()
    {
        $expected = [
            ['First Name', 'Last Name', 'E-mail'],
            ['john', 'doe', 'john.doe@example.com'],
            ['jane', 'doe', 'jane.doe@example.com'],
        ];

        $tmp = new SplTempFileObject();
        foreach ($expected as $row) {
            $tmp->fputcsv($row);
        }

        $reader = Reader::createFromFileObject($tmp)->setHeaderOffset(0);
        $result = (new Statement())->offset(1)->limit(1)->process($reader);
        $this->assertSame(
            '[{"First Name":"jane","Last Name":"doe","E-mail":"jane.doe@example.com"}]',
            json_encode($result)
        );
        $result->preserveRecordOffset(true);
        $this->assertSame(
            '{"2":{"First Name":"jane","Last Name":"doe","E-mail":"jane.doe@example.com"}}',
            json_encode($result)
        );
    }
}
