<?php

namespace Admin\Core\Contracts\Migrations;

use Admin\Core\Contracts\DataStore;
use Admin\Core\Contracts\Migrations\Concerns\HasIndex;
use Admin\Core\Contracts\Migrations\Concerns\MigrationEvents;
use Admin\Core\Contracts\Migrations\MigrationBuilder;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Column extends Command
{
    use HasIndex,
        MigrationEvents;

    /*
     * Set input of field for line, writeln support etx...
     */
    public function setInput(InputInterface $input)
    {
        $this->input = $input;
    }

    /*
     * Set output of field for line, writeln support etx...
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }
}