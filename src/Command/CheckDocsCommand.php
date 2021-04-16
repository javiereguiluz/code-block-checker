<?php

declare(strict_types=1);

namespace SymfonyCodeBlockChecker\Command;

use Doctrine\RST\Builder\Documents;
use Doctrine\RST\Builder\ParseQueue;
use Doctrine\RST\Builder\ParseQueueProcessor;
use Doctrine\RST\ErrorManager;
use Doctrine\RST\Event\PostNodeCreateEvent;
use Doctrine\RST\Event\PostParseDocumentEvent;
use Doctrine\RST\Meta\CachedMetasLoader;
use Doctrine\RST\Meta\Metas;
use Doctrine\RST\Parser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use SymfonyCodeBlockChecker\Listener\ValidCodeNodeListener;
use SymfonyDocsBuilder\BuildConfig;


class CheckDocsCommand extends Command
{
    protected static $defaultName = 'verify:docs';

    /** @var SymfonyStyle */
    private $io;

    public function __construct()
    {
        parent::__construct(self::$defaultName);
    }

    protected function configure()
    {
        $this
            ->addArgument('source-dir', InputArgument::REQUIRED, 'RST files Source directory')
            ->addArgument('files', InputArgument::IS_ARRAY + InputArgument::REQUIRED, 'RST files that should be verified.')
            ->setDescription('Make sure the docs blocks are valid')

        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);

        $files = $input->getArgument('files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sourceDir = $input->getArgument('source-dir');
        if (!file_exists($sourceDir)) {
            throw new \InvalidArgumentException(sprintf('RST source directory "%s" does not exist', $sourceDir));
        }
        $buildConfig = new BuildConfig();
        $buildConfig->setContentDir($sourceDir);

        $kernel = \SymfonyDocsBuilder\KernelFactory::createKernel($buildConfig);
        $errorManager = new ErrorManager($kernel->getConfiguration());
        $eventManager = $kernel->getConfiguration()->getEventManager();
        $eventManager->addEventListener(PostNodeCreateEvent::POST_NODE_CREATE, new ValidCodeNodeListener($errorManager));

        $filesystem = new Filesystem();
        $metas = new Metas();
        $documents = new Documents($filesystem, $metas);

        $queueProcessor = new ParseQueueProcessor($kernel, $errorManager, $metas, $documents, $sourceDir, '/foo/target', 'rst');

        $files = $input->getArgument('files');
        $parseQueue = new ParseQueue();
        foreach ($files as $filename) {
            $parseQueue->addFile(ltrim($filename, '/'), true);
        }

        $queueProcessor->process($parseQueue);

        $errorCount = count($errorManager->getErrors());
        if ($errorCount > 0) {
            $this->io->error(sprintf('Build completed with %s errors', $errorCount));
            return Command::FAILURE;
        }
        $this->io->success('Build completed successfully!');

        return Command::SUCCESS;
    }
}