<?php

namespace Plugin\BlastengineMailer\Command;

use Plugin\BlastengineMailer\Repository\ConfigRepository;
use Plugin\BlastengineMailer\Service\BounceService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * blastengine のエラー停止一覧を取得して会員の宛先状態に反映する（cron 用）。
 *
 *   bin/console blastengine:bounce-sync
 */
class BounceSyncCommand extends Command
{
    protected static $defaultName = 'blastengine:bounce-sync';

    public function __construct(private BounceService $bounce, private ConfigRepository $configRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('blastengine のエラー停止一覧を取得し、会員の宛先状態（宛先不明）に反映する')
            ->addOption('force', null, InputOption::VALUE_NONE, '設定でエラー停止連携が無効でも実行する');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $Config = $this->configRepository->get();
        if (!$Config->isBounceSyncEnabled() && !$input->getOption('force')) {
            $io->warning('エラー停止連携が無効です（blastengine連携設定で有効にするか --force）。');

            return Command::SUCCESS;
        }
        try {
            $r = $this->bounce->fetchFromBlastengine($Config, function (int $pages, int $fetched, int $marked) use ($io) {
                $io->writeln(sprintf('  page %d: 取得 %d 件 / 反映 %d 名', $pages, $fetched, $marked));
            });
            $io->success(sprintf('取得 %d 件（%d ページ、%s）。宛先不明として反映した会員: %d 名', $r['fetched'], $r['pages'], $r['since'] ? $r['since'].' 以降' : '全件', $r['marked']));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
