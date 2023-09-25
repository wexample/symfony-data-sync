<?php

namespace Wexample\SymfonyDataSync\Class;

class RelationItemPlaceHolder
{
    public function __construct(
        private string $label
    ) {
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getLabel(): string
    {
        return '['.$this->label.']';
    }
}
