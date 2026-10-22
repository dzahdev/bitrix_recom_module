<?php

declare(strict_types=1);

namespace Dzahdev\Recom\Cli;

use Dzahdev\Recom\Training\Trainer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * php bitrix/bitrix.php dzahdev.recom:train [--full] - для сайтов с Symfony Console (composer).
 * Без composer тот же запуск - tools/train.php.
 * Ошибка обучения не перехватывается: консоль печатает ее и завершается с ненулевым кодом.
 */
class TrainCommand extends Command
{
	protected function configure(): void
	{
		$this
			->setName('dzahdev.recom:train')
			->setDescription('Train recommendation models of module dzahdev.recom')
			->addOption('full', null, InputOption::VALUE_NONE, 'Full training from scratch with NDCG@10 evaluation');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$result = Trainer::create()->run($input->getOption('full') ? Trainer::MODE_FULL : Trainer::MODE_INCREMENTAL);

		$output->writeln($result->summary());

		return 0;
	}
}
