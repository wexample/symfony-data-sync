<?php

namespace Wexample\SymfonyDataSync\Class;

use MathieuViossat\Util\ArrayToTextTable;

/**
 * @deprecated TODO use RenderableResponse instead, allowing several render formats.
 */
class FlatDataset
{
    private array $data = [];

    public function __construct(array $data = null)
    {
        if ($data) {
            $this->setData($data);
        }
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function toTextTable(): ArrayToTextTable
    {
        return new ArrayToTextTable(
            $this->getData()
        );
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data)
    {
        $this->data = $data;
    }
}
