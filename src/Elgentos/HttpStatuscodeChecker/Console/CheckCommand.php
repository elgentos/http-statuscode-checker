<?php

namespace Elgentos\HttpStatuscodeChecker\Console;

use GuzzleHttp\Exception\TooManyRedirectsException;
use League\Csv\Writer;
use SplTempFileObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use League\Csv\Reader;
use Symfony\Component\Console\Helper\Table;
use GuzzleHttp\Client;

class CheckCommand extends Command
{
    const LOGO = '                              
    __    __  __                   __        __                            __                __              __            
   / /_  / /_/ /_____        _____/ /_____ _/ /___  ________________  ____/ /__        _____/ /_  ___  _____/ /_____  _____
  / __ \/ __/ __/ __ \______/ ___/ __/ __ `/ __/ / / / ___/ ___/ __ \/ __  / _ \______/ ___/ __ \/ _ \/ ___/ //_/ _ \/ ___/
 / / / / /_/ /_/ /_/ /_____(__  ) /_/ /_/ / /_/ /_/ (__  ) /__/ /_/ / /_/ /  __/_____/ /__/ / / /  __/ /__/ ,< /  __/ /    
/_/ /_/\__/\__/ .___/     /____/\__/\__,_/\__/\__,_/____/\___/\____/\__,_/\___/      \___/_/ /_/\___/\___/_/|_|\___/_/     
             /_/                                                                                                           
                   by elgentos';

    const VERSION = '0.1.0';

    protected $config;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run checker on a list of URLs';

    /**
     * Supported file types
     *
     * @var array
     */
    protected $supportedFileTypes = ['csv'];

    /**
     * Default delay between Guzzle requests in ms
     *
     * @var int
     */
    protected $defaultDelay = 500;

    /**
     * @var
     */
    protected $table;

    /**
     *
     */
    protected function configure()
    {
        $this
            ->setName($this->name)
            ->setDescription($this->description)
            ->addOption('url-header', 'u', InputOption::VALUE_REQUIRED, 'Name of header in CSV file for URL', 'url')
            ->addOption('base-uri', 'b', InputOption::VALUE_REQUIRED, 'Set the base URI to be prepended for relative URLs')
            ->addOption('delay', 'd', InputOption::VALUE_REQUIRED, 'Delay between requests', 500)
            ->addOption('file-output', 'f', InputOption::VALUE_OPTIONAL, 'Write output to CSV file')
            ->addArgument('file', InputOption::VALUE_REQUIRED, 'Filename to parse for URLs');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \League\Csv\Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        $this->output->writeln(self::LOGO);
        $this->output->writeln('                        v' . self::VERSION);

        if (!$input->getArgument('file')) {
            $output->writeln('<error>No filename has been given.</error>');
            return 1;
        }

        $file = $input->getArgument('file');

        $fileType = $this->checkFileType($file);

        $urls = [];
        if ($fileType == 'csv') {
            $urls = $this->getUrlsFromCsv($file);
        }

        $this->output->writeln(sprintf('<info>Validating %s URLs...</info>', count($urls)));

        if ($input->getOption('base-uri')) {
            $urls = array_map([$this, 'prependBaseUri'], $urls);
        }

        $urls = array_filter($urls, [$this, 'validateUrl']);

        $this->output->writeln(sprintf('<info>Processing %s URLs...</info>', count($urls)));

        $section = $output->section();
        $this->table = new Table($section);
        $this->table->setHeaders(['URL', 'Status Code']);
        $this->table->render();

        $output = $this->checkForStatusCodes($urls);
        if ($this->input->getOption('file-output')) {
            $this->writeOutputToFile($output, $this->input->getOption('file-output'));
        }

        $this->output->writeln('Done.');

        return 0;
    }

    /**
     * @param $file
     * @return string
     * @throws \Exception
     */
    private function checkFileType($file) : string
    {
        if (!file_exists($file)) {
            throw new \Exception(sprintf('Filename %s does not exist.', $file));
        }

        $extension = substr($file, -4);
        foreach ($this->supportedFileTypes as $supportedFileType) {
            if ($extension == '.' . $supportedFileType) {
                return $supportedFileType;
            }
        }

        throw new \Exception(sprintf('File type %s not supported. Supported list: %s', $extension, implode(', ', $this->supportedFileTypes)));
    }

    /**
     * @param $file
     * @return array
     * @throws \League\Csv\Exception
     */
    private function getUrlsFromCsv($file) : array
    {
        $csv = Reader::createFromPath($file, 'r');
        $csv->setHeaderOffset(0);

        $urlHeader = $this->input->getOption('url-header');

        $urls = [];

        foreach ($csv as $record) {
            if (isset($record[$urlHeader])) {
                $urls[] = $record[$urlHeader];
            }
        }

        return $urls;
    }

    /**
     * @param $url
     * @return string
     */
    private function prependBaseUri($url) : string
    {
        return $this->input->getOption('base-uri') . $url;
    }

    /**
     * @param $url
     * @return bool
     */
    private function validateUrl($url) : bool
    {
        $validated = filter_var($url, FILTER_VALIDATE_URL);

        if (!$validated) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http','https'])) {
            return false;
        }

        return true;
    }

    /**
     * @param array $urls
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function checkForStatusCodes(array $urls) : array
    {
        $client = new Client([
            'http_errors' => false,
            'delay' => $this->getDelay()]
        );
        $rows = [];
        foreach ($urls as $url) {
            try {
                $statusCode = $this->getStatusCode($client, $url);
            } catch (TooManyRedirectsException $e) {
                $statusCode = 'Redirect loop';
            }
            $row = [$url, $statusCode];
            $this->table->appendRow($row);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param Client $client
     * @param string $url
     * @return int
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function getStatusCode(Client $client, string $url) : int
    {
        /** @var \Psr\Http\Message\ResponseInterface $response */
        $response = $client->request('GET', $url);
        return $response->getStatusCode();
    }

    /**
     * @return int
     */
    private function getDelay() : int
    {
        return (int) $this->input->getOption('delay') ?? $this->defaultDelay;
    }

    /**
     * @param $output
     * @param $outputFile
     * @throws \League\Csv\CannotInsertRecord
     */
    private function writeOutputToFile($output, $outputFile)
    {
        $csv = Writer::createFromPath($outputFile, 'w');
        $csv->insertOne(['url', 'status_code']);
        $csv->insertAll($output);
    }
}
