<?php

namespace ProcessMaker\ScriptRunners;

use ProcessMaker\Exception\ScriptLanguageNotSupported;
use ProcessMaker\Models\ScriptExecutor;

class ScriptRunner
{
    /**
     * Concrete script runner
     *
     * @var \ProcessMaker\ScriptRunners\Base $runner
     */
    private $runner;

    public function __construct(ScriptExecutor $executor)
    {
        $this->runner = $this->getScriptRunner($executor);
    }

    /**
     * Run a script code.
     *
     * @param string $code
     * @param array $data
     * @param array $config
     * @param integer $timeout
     * @param \ProcessMaker\Models\User $user
     *
     * @return array
     * @throws \RuntimeException
     */
    public function run($code, array $data, array $config, $timeout = 60, $user)
    {
        return $this->runner->run($code, $data, $config, $timeout, $user);
    }

    /**
     * Get a runner instance from executor
     *
     * @param ScriptExecutor $executor
     *
     * @return \ProcessMaker\ScriptRunners\Base
     * @throws \ProcessMaker\Exception\ScriptLanguageNotSupported
     */
    private function getScriptRunner(ScriptExecutor $executor)
    {
        $language = strtolower($executor->language);
        $runner = config("script-runners.{$language}.runner");
        if (!$runner) {
            throw new ScriptLanguageNotSupported($language);
        } else {
            $class = "ProcessMaker\\ScriptRunners\\{$runner}";
            return new $class($executor);
        }
    }
}
