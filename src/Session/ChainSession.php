<?php

namespace Yaroslam\SSH2\Session;

use Yaroslam\SSH2\Session\Commands\CommandCase;
use Yaroslam\SSH2\Session\Commands\CommandElse;
use Yaroslam\SSH2\Session\Commands\CommandExec;
use Yaroslam\SSH2\Session\Commands\CommandFor;
use Yaroslam\SSH2\Session\Commands\CommandIf;
use Yaroslam\SSH2\Session\Commands\CommandSwitch;
use Yaroslam\SSH2\Session\Commands\CommandThen;
use Yaroslam\SSH2\Session\Commands\EndElseCommand;
use Yaroslam\SSH2\Session\Commands\EndForCommand;
use Yaroslam\SSH2\Session\Commands\EndIfCommand;
use Yaroslam\SSH2\Session\Commands\EndSwitchCommand;
use Yaroslam\SSH2\Session\Commands\EndThenCommand;
use Yaroslam\SSH2\Session\Commands\Exceptions\WorkflowTypeOrderException;

/**
 * Класс сессии, которая сохраняет состояние между вызовами и может пользоваться всеми командами
 *
 * @todo добавить case switch
 */
class ChainSession extends AbstractSession
{
    /**
     * @var array глобальный контекст выполнения
     */
    private array $chainContext;

    /**
     * @var resource ssh2 ресурс
     */
    private $shell;

    /**
     * @var array массив, хранящий все записанные в цепочку команды
     */
    private array $chainCommands;

    /**
     * @var CommandThen|CommandElse|CommandCase|CommandFor последняя добавленная команда
     *
     * @todo подумать над переименованием в ластБлок
     */
    private CommandThen|CommandElse|CommandCase|CommandFor $lastCommand;

    /**
     * @var int текущий уровень глубины в месте добавления команд
     */
    private int $deepLevel;

    /**
     * @var array массив, хранящий глобальный список операторов, согласно их глубине
     */
    public array $operatorsGraph;

    /**
     * @var array массив, хранящий список блоков, согласно их глубине
     */
    public array $blockGraph;

    /**
     * @var string глобальное наименование текущего блока
     */
    private string $currBlock;

    /**
     * @var int глобальный номер текущего case
     */
    private int $currCaseCounter;

    private int $currForCounter;

    private int $globalForCounter;

    /**
     * @var array глобальный список типов команд, согласно порядку их выполнения
     */
    private array $workFlowTypes;

    /**
     * @var array массив сохраненных функций
     */
    private array $functions;

    /**
     * Инициализирует сессию
     *
     * @api
     *
     * @param  bool  $withFakeStart флаг запуска сессии с или без "нулевого старта". Если равен true, то старт состоится. Если равен false, то нет. По умолчанию равен true
     * @return $this
     */
    public function initChain(bool $withFakeStart = true): ChainSession
    {
        $this->shell = ssh2_shell($this->connector->getSsh2Connect());
        $withFakeStart ? $this->fakeStart() :
        $this->deepLevel = 0;
        $this->operatorsGraph = [];
        $this->blockGraph = [];
        $this->workFlowTypes = [];
        $this->functions = [];
        $this->chainContext = [];
        $this->chainCommands = [];
        $this->currCaseCounter = 0;
        $this->currForCounter = 0;
        $this->globalForCounter = 0;

        return $this;
    }

    //    fake start для того, что бы первая выполняемая команда не выводилась вместе с сообщениями старта системы

    /**
     * Совершает нулевой старт
     *
     * @internal
     */
    private function fakeStart(): void
    {
        $this->deepLevel = 0;
        $this->operatorsGraph = [];
        $this->blockGraph = [];
        $this->workFlowTypes = [];
        $this->functions = [];
        $this->chainContext = [];
        $this->chainCommands = [];
        $this->exec('echo start', false)->apply();
    }

    /**
     * Исполняет exec команду согласно переданным параметрам.
     *
     * @param  string  $cmdCommand текс команды
     * @param  bool  $needProof  флаг проверки значения статус код по умолчанию равен true, если равен true - проверка проводится, если false - нет.
     * @param  int  $timeout время задержки перед выполнением
     * @return $this
     */
    public function exec(string $cmdCommand, bool $needProof = true, int $timeout = 4): ChainSession
    {
        $execCommand = new CommandExec($cmdCommand, $needProof, $timeout);
        if ($this->deepLevel == 0) {
            $this->chainCommands[] = $execCommand;
        } else {
            $this->blockGraph[$this->currBlock]->addToBody($execCommand);
        }
        $this->workFlowTypes[] = $execCommand->getCommandType();

        return $this;
    }

    /**
     * Выполняет if команду согласно переданным параметрам
     *
     * @param  string  $cmdCommand текс команды
     * @param  string  $ifStatement строка, вхождение которой будет проверяться на вхождение в результате исполнения команды
     * @return $this
     */
    public function if(string $cmdCommand, string $ifStatement): ChainSession
    {
        $newIf = new CommandIf($cmdCommand, $ifStatement);
        if ($this->deepLevel == 0) {
            $this->chainCommands[] = $newIf;
        } else {
            $this->lastCommand->addToBody($newIf);
        }
        $this->deepLevel += 1;
        $this->operatorsGraph[$this->deepLevel] = $newIf;
        $this->workFlowTypes[] = $newIf->getCommandType();

        return $this;
    }

    /**
     * Окончание if команды
     *
     * @return $this
     */
    public function endIf(): ChainSession
    {
        $this->deepLevel -= 1;
        $this->workFlowTypes[] = EndIfCommand::getCommandType();

        return $this;

    }

    /**
     * Выполняет then команду
     *
     * @return $this
     */
    public function then(): ChainSession
    {
        $this->lastCommand = new CommandThen();
        $this->currBlock = $this->deepLevel.'.then';
        $this->blockGraph[$this->currBlock] = $this->lastCommand;
        $this->deepLevel += 1;
        $this->workFlowTypes[] = $this->lastCommand->getCommandType();

        return $this;

    }

    /**
     * Окончание then команды
     *
     * @return $this
     */
    public function endThen(): ChainSession
    {
        $this->deepLevel -= 1;
        $this->operatorsGraph[$this->deepLevel]->addToBody($this->blockGraph[$this->deepLevel.'.then'], 'then');
        $this->workFlowTypes[] = EndThenCommand::getCommandType();

        return $this;

    }

    /**
     * Выполняет else команду
     *
     * @return $this
     */
    public function else(): ChainSession
    {
        $this->lastCommand = new CommandElse();
        $this->currBlock = $this->deepLevel.'.else';
        $this->blockGraph[$this->currBlock] = $this->lastCommand;
        $this->deepLevel += 1;
        $this->workFlowTypes[] = $this->lastCommand->getCommandType();

        return $this;

    }

    /**
     * Окончание else команды
     *
     * @return $this
     */
    public function endElse(): ChainSession
    {
        $this->deepLevel -= 1;
        $this->operatorsGraph[$this->deepLevel]->addToBody($this->blockGraph[$this->deepLevel.'.else'], 'else');
        $this->workFlowTypes[] = EndElseCommand::getCommandType();

        return $this;

    }

    /**
     * Выполняет for команду
     *
     * @param  int  $start старт счетчика
     * @param  int  $stop окончание счетчика
     * @param  int  $step шаг счетчик, по умолчанию равен 1
     * @return $this
     */
    public function for(int $start, int $stop, int $step = 1): ChainSession
    {
        $newFor = new CommandFor($start, $stop, $step);
        if ($this->deepLevel == 0) {
            $this->chainCommands[] = $newFor;
        } else {
            $this->lastCommand->addToBody($newFor);
        }
        $this->lastCommand = $newFor;
        $this->globalForCounter += 1;
        $this->currForCounter = $this->globalForCounter;
        $this->currBlock = $this->deepLevel.'.for.'.$this->currForCounter;
        $this->deepLevel += 1;
        $this->blockGraph[$this->currBlock] = $this->lastCommand;
        $this->workFlowTypes[] = $newFor->getCommandType();

        return $this;
    }

    /**
     * окончание for команды
     *
     * @return $this
     */
    //доделать
    public function endFor(): ChainSession
    {
        $this->currForCounter -= 1;
        $this->deepLevel -= 1;
        $this->currBlock = ! getPrevArrayKey($this->blockGraph, $this->currBlock) ?
            $this->currBlock : getPrevArrayKey($this->blockGraph, $this->currBlock);
        var_dump($this->currBlock);
        $this->workFlowTypes[] = EndSwitchCommand::getCommandType();

        return $this;
    }

    public function switch(string $cmdCommand, bool $breakable = true, int $timeout = 4): ChainSession
    {
        $newSwitch = new CommandSwitch($cmdCommand, $breakable, $timeout);
        if ($this->deepLevel == 0) {
            $this->chainCommands[] = $newSwitch;
        } else {
            $this->lastCommand->addToBody($newSwitch);
        }
        $this->deepLevel += 1;
        $this->operatorsGraph[$this->deepLevel] = $newSwitch;
        $this->workFlowTypes[] = $newSwitch->getCommandType();

        return $this;
    }

    public function endSwitch(): ChainSession
    {
        $this->deepLevel -= 1;
        $this->workFlowTypes[] = EndForCommand::getCommandType();

        return $this;
    }

    public function case(string $caseStatement): ChainSession
    {
        $this->lastCommand = new CommandCase($caseStatement);
        $this->currCaseCounter += 1;
        $this->currBlock = $this->deepLevel.'.case'.$this->currCaseCounter;
        $this->blockGraph[$this->currBlock] = $this->lastCommand;
        $this->deepLevel += 1;
        $this->workFlowTypes[] = $this->lastCommand->getCommandType();

        return $this;
    }

    public function endCase()
    {
        $this->deepLevel -= 1;
        $this->operatorsGraph[$this->deepLevel]->addToBody($this->blockGraph[$this->deepLevel.'.case'.$this->currCaseCounter]);
        $this->currCaseCounter -= 1;

        return $this;
    }

    /**
     * Возвращает контекст выполнения сессии
     *
     * @param  array  $con При обращении параметр не указывается
     * @param  array  $output При обращении параметр не указывается
     * @return array|array[]
     */
    public function getExecContext(array $con = [], array $output = ['command' => [], 'exit_code' => [], 'output' => []]): array
    {
        if ($con == []) {
            $con = $this->chainContext;
        }
        foreach ($con as $context) {
            if (array_key_exists('command', $con)) {
                $output['command'][] = $con['command'];
                $output['exit_code'][] = $con['exit_code'];
                $output['output'][] = $con['output'];

                return $output;
            } else {
                $output = $this->getExecContext($context, $output);
            }
        }

        return $output;
    }

    /**
     * Проверяет workflow на следование правилам построения потока выполнения
     *
     * @internal
     *
     * @param  array  $workflow текущий поток исполнения
     *
     * @throws WorkflowTypeOrderException
     * @throws WorkflowTypeOrderException
     */
    private function checkWorkFlow(array $workflow): bool
    {
        $rules = require __DIR__.'/Commands/Rules/Rules.php';
        for ($i = 0; $i < count($workflow) - 1; $i++) {
            if (! in_array($workflow[$i + 1], $rules[$workflow[$i]->name])) {
                throw new WorkflowTypeOrderException([
                    'prev' => $workflow[$i],
                    'next' => $workflow[$i + 1]]);
            }
        }

        return true;
    }

    /**
     * Определяет старт функции
     *
     * @param  string  $name наименование функции
     * @return $this
     */
    public function declareFunction(string $name): ChainSession
    {
        $this->functions[$name] = [];

        return $this;
    }

    /**
     * Определяет конец функции
     *
     * @param  string  $name наименование функции
     */
    public function endFunction(string $name): void
    {
        $this->functions[$name] = ['chain' => $this->chainCommands,
            'workflow' => $this->workFlowTypes,
        ];
    }

    /**
     * Использует функцию с переданным именем
     *
     * @param  string  $name имя используемой функции
     * @return $this
     *
     * @throws WorkflowTypeOrderException
     */
    public function useFunction(string $name): ChainSession
    {
        if ($this->checkWorkFlow($this->functions[$name]['workflow'])) {
            foreach ($this->functions[$name]['chain'] as $command) {
                $command->execution($this->shell);
            }
        }

        return $this;
    }

    /**
     * Применяет всю цепочку команд в рамках сессии
     *
     * @return $this
     *
     * @throws WorkflowTypeOrderException
     */
    public function apply(): ChainSession
    {
        if ($this->checkWorkFlow($this->workFlowTypes)) {
            foreach ($this->chainCommands as $command) {
                $this->chainContext[] = $command->execution($this->shell);
            }
        }

        return $this;
    }
}
