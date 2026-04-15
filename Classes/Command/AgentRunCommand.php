<?php

declare(strict_types=1);

namespace Hn\Agent\Command;

use Hn\Agent\Domain\AgentTaskRepository;
use Hn\Agent\EventListener\AgentEventRelay;
use Hn\Agent\Service\AgentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Configuration\Tca\TcaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(
    name: 'agent:run',
    description: 'Process pending AI agent tasks',
)]
class AgentRunCommand extends Command
{
    public function __construct(
        private readonly AgentService $agentService,
        private readonly AgentTaskRepository $repository,
        private readonly AgentEventRelay $agentEventRelay,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Process pending AI agent tasks');
        $this->setHelp('Picks up pending agent tasks and processes them through the LLM agent loop. Can also resume failed or in-progress tasks by UID.');
        $this->addOption('task', 't', InputOption::VALUE_REQUIRED, 'Process a specific task by UID (any status except ended)');
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of pending tasks to process', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Ensure TCA is loaded
        $tcaFactory = GeneralUtility::getContainer()->get(TcaFactory::class);
        $GLOBALS['TCA'] = $tcaFactory->get();

        $specificTask = $input->getOption('task');
        $limit = (int)$input->getOption('limit');

        if ($specificTask !== null) {
            return $this->processSpecificTask((int)$specificTask, $output);
        }

        return $this->processPendingTasks($limit, $output);
    }

    private function processSpecificTask(int $taskUid, OutputInterface $output): int
    {
        $output->writeln('Processing task #' . $taskUid . '...');

        $this->agentEventRelay->setCallback(function (string $event, array $data) use ($output) {
            match ($event) {
                'llm_start' => $output->writeln(sprintf('  Iteration %d: calling LLM...', $data['iteration'] + 1)),
                'assistant_message' => $output->writeln(sprintf(
                    '  Iteration %d: tool_calls=[%s]',
                    $data['iteration'] + 1,
                    !empty($data['message']['tool_calls'])
                        ? implode(', ', array_map(fn($tc) => $tc['function']['name'] ?? '?', $data['message']['tool_calls']))
                        : 'none'
                )),
                'tool_start' => $output->writeln(sprintf('    Executing: %s', $data['tool_name'])),
                'tool_result' => $output->writeln(sprintf('    Result: %s (%d chars)', $data['tool_name'], strlen($data['content'] ?? ''))),
                default => null,
            };
        });

        try {
            $this->agentService->processTask($taskUid);
            $output->writeln('Task #' . $taskUid . ' completed.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Task #' . $taskUid . ' failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } finally {
            $this->agentEventRelay->clearCallback();
        }
    }

    private function processPendingTasks(int $limit, OutputInterface $output): int
    {
        $tasks = $this->repository->findPendingTasks($limit);

        if (empty($tasks)) {
            $output->writeln('No pending tasks found.');
            return Command::SUCCESS;
        }

        $output->writeln('Found ' . count($tasks) . ' pending task(s).');
        $hasFailure = false;

        foreach ($tasks as $task) {
            $output->writeln('');
            $output->writeln('Processing: <info>' . $task['title'] . '</info> (#' . $task['uid'] . ')');

            $this->agentEventRelay->setCallback(function (string $event, array $data) use ($output) {
                match ($event) {
                    'llm_start' => $output->writeln(sprintf('  Iteration %d: calling LLM...', $data['iteration'] + 1)),
                    'assistant_message' => $output->writeln(sprintf(
                        '  Iteration %d: tool_calls=[%s]',
                        $data['iteration'] + 1,
                        !empty($data['message']['tool_calls'])
                            ? implode(', ', array_map(fn($tc) => $tc['function']['name'] ?? '?', $data['message']['tool_calls']))
                            : 'none'
                    )),
                    'tool_start' => $output->writeln(sprintf('    Executing: %s', $data['tool_name'])),
                    'tool_result' => $output->writeln(sprintf('    Result: %s (%d chars)', $data['tool_name'], strlen($data['content'] ?? ''))),
                    default => null,
                };
            });

            try {
                $this->agentService->processTask((int)$task['uid']);
                $output->writeln('  Status: <info>ended</info>');
            } catch (\Throwable $e) {
                $output->writeln('  Status: <error>failed</error> — ' . $e->getMessage());
                $hasFailure = true;
            } finally {
                $this->agentEventRelay->clearCallback();
            }
        }

        return $hasFailure ? Command::FAILURE : Command::SUCCESS;
    }
}
