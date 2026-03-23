<?php

namespace Elgentos\HttpStatuscodeChecker\Console;

use Elgentos\Parser;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Pool;
use League\Csv\CannotInsertRecord;
use League\Csv\Exception;
use League\Csv\Writer;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use League\Csv\Reader;
use Symfony\Component\Console\Helper\ProgressBar;
use GuzzleHttp\Client;

class CheckCommand extends Command
{
    public const LOGO = '                              
    __    __  __                   __        __                            __                __              __            
   / /_  / /_/ /_____        _____/ /_____ _/ /___  ________________  ____/ /__        _____/ /_  ___  _____/ /_____  _____
  / __ \/ __/ __/ __ \______/ ___/ __/ __ `/ __/ / / / ___/ ___/ __ \/ __  / _ \______/ ___/ __ \/ _ \/ ___/ //_/ _ \/ ___/
 / / / / /_/ /_/ /_/ /_____(__  ) /_/ /_/ / /_/ /_/ (__  ) /__/ /_/ / /_/ /  __/_____/ /__/ / / /  __/ /__/ ,< /  __/ /    
/_/ /_/\__/\__/ .___/     /____/\__/\__,_/\__/\__,_/____/\___/\____/\__,_/\___/      \___/_/ /_/\___/\___/_/|_|\___/_/     
             /_/                                                                                                           
                   by elgentos';

    public const VERSION = '1.2.1';
    protected InputInterface $input;
    protected OutputInterface $output;
    protected string $name = 'check';
    protected string $description = 'Run checker on a list of URLs';
    protected array $supportedFileTypes = ['csv', 'xml'];
    protected int $defaultDelay = 0;
    protected int $defaultConcurrency = 10;
    protected bool $trackRedirects;
    protected ?ProgressBar $progressBar = null;
    protected ?Writer $csvWriter = null;

    protected function configure(): void
    {
        $this
            ->setName($this->name)
            ->setDescription($this->description)
            ->addOption('url-header', 'u', InputOption::VALUE_REQUIRED, 'Name of header in CSV file for URL', 'url')
            ->addOption('base-uri', 'b', InputOption::VALUE_REQUIRED, 'Set the base URI to be used (existing base URI will be replaced)')
            ->addOption('user-agent', 'a', InputOption::VALUE_OPTIONAL, 'Set the user agent to be used for the requests')
            ->addOption('concurrency', 'c', InputOption::VALUE_REQUIRED, 'Number of concurrent requests', 10)
            ->addOption('delay', 'd', InputOption::VALUE_REQUIRED, 'Delay between requests in ms', 0)
            ->addOption('file-output', 'f', InputOption::VALUE_OPTIONAL, 'Write output to CSV file')
            ->addOption('track-redirects', 't', InputOption::VALUE_NONE, 'Flag to track intermediate 301/302 status codes in output too')
            ->addArgument('file', InputOption::VALUE_REQUIRED, 'Filename to parse for URLs');
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        $this->output->writeln(self::LOGO);
        $this->output->writeln('v' . self::VERSION);

        if (!$input->getArgument('file')) {
            $output->writeln('<error>No filename has been given.</error>');
            return 1;
        }

        $this->trackRedirects = $input->getOption('track-redirects');

        $file = $input->getArgument('file');

        $fileType = $this->checkFileType($file);

        $urls = match ($fileType) {
            'csv' => $this->getUrlsFromCsv($file),
            'xml' => $this->getUrlsFromXml($file),
        };

        $this->output->writeln(sprintf('<info>Validating %s URLs...</info>', count($urls)));

        if ($input->getOption('base-uri')) {
            $urls = array_map([$this, 'prependBaseUri'], $urls);
        }

        $urls = array_filter($urls, [$this, 'validateUrl']);

        // If file output is requested and the file already exists, skip URLs that have already been checked
        $outputFile = $this->input->getOption('file-output');
        if ($outputFile && file_exists($outputFile)) {
            $existingUrls = $this->getUrlsFromOutputFile($outputFile);
            if (!empty($existingUrls)) {
                $beforeCount = count($urls);
                $urls = array_values(array_diff($urls, $existingUrls));
                $skipped = $beforeCount - count($urls);
                $this->output->writeln(sprintf('<info>Skipping %d URLs already present in %s</info>', $skipped, $outputFile));
            }
        }

        $urlCount = count($urls);
        $this->output->writeln(sprintf('<info>Processing %s URLs...</info>', $urlCount));

        // Progress bar overwrites itself on the last line of output
        $this->progressBar = new ProgressBar($output, $urlCount);
        $this->progressBar->setFormat(" %current%/%max% [%bar%] %percent:3s%% %elapsed:8s% / %estimated:-8s% %memory:6s%");
        $this->progressBar->start();

        // Table header printed once, rows stream below without redrawing
        $this->output->writeln('');
        $this->output->writeln(str_pad('URL', 80) . ' Status Code');
        $this->output->writeln(str_repeat('-', 92));

        // Initialize CSV writer if file output is requested
        if ($outputFile) {
            $this->initializeCsvWriter($outputFile, file_exists($outputFile));
        }

        $this->checkForStatusCodes($urls);

        $this->progressBar->finish();
        $this->output->writeln('');
        $this->output->writeln('Done.');

        return 0;
    }

    /**
     * @param string $file
     * @return string
     * @throws \Exception
     */
    private function checkFileType(string $file): string
    {
        if (!file_exists($file)) {
            throw new \Exception(sprintf('Filename %s does not exist.', $file));
        }

        $extension = substr($file, -4);
        foreach ($this->supportedFileTypes as $supportedFileType) {
            if ($extension === '.' . $supportedFileType) {
                return $supportedFileType;
            }
        }

        throw new \Exception(sprintf('File type %s not supported. Supported list: %s', $extension, implode(', ', $this->supportedFileTypes)));
    }

    /**
     * @param string $file
     * @return array
     * @throws Exception
     */
    private function getUrlsFromCsv(string $file): array
    {
        $csv = Reader::createFromPath($file);
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

    private function getUrlsFromXml(mixed $file): array
    {
        $data = Parser::readSimple($file);

        if (isset($data['url'])) {
            $urls = [];
            foreach ($data['url'] as $url) {
                $urls[] = $url['loc'];
            }
            return $urls;
        }

        if (isset($data['sitemap'])) {
            $this->output->writeln(sprintf('<info>Found sitemap index with %d sub-sitemaps, fetching...</info>', count($data['sitemap'])));
            $urls = [];
            $client = new Client([
                'headers' => ['user-agent' => $this->getUserAgent()],
                'http_errors' => false,
                'verify' => false,
            ]);
            foreach ($data['sitemap'] as $sitemap) {
                $sitemapUrl = $sitemap['loc'];
                $this->output->writeln(sprintf('<info>Fetching sub-sitemap: %s</info>', $sitemapUrl));
                try {
                    usleep($this->getDelay() * 1000);
                    $response = $client->request('GET', $sitemapUrl);
                    if ($response->getStatusCode() === 200) {
                        $urls = array_merge($urls, $this->getUrlsFromXmlString($response->getBody()->getContents()));
                    } else {
                        $this->output->writeln(sprintf('<error>Failed to fetch %s (HTTP %d)</error>', $sitemapUrl, $response->getStatusCode()));
                    }
                } catch (\Exception $e) {
                    $this->output->writeln(sprintf('<error>Failed to fetch %s: %s</error>', $sitemapUrl, $e->getMessage()));
                }
            }
            return $urls;
        }

        return [];
    }

    private function getUrlsFromXmlString(string $xmlContent): array
    {
        $xml = simplexml_load_string($xmlContent);
        if ($xml === false) {
            return [];
        }

        $xml->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urls = [];

        // Handle regular sitemap with <url> entries
        foreach ($xml->xpath('//sm:url/sm:loc') as $loc) {
            $urls[] = (string) $loc;
        }

        // Handle sitemap index with <sitemap> entries (nested index)
        if (empty($urls)) {
            foreach ($xml->xpath('//sm:sitemap/sm:loc') as $loc) {
                $urls[] = (string) $loc;
            }
        }

        return $urls;
    }

    /**
     * @param string $url
     * @return string
     */
    private function prependBaseUri(string $url): string
    {
        $parsedUrl = parse_url($url);
        $baseUri = rtrim($this->input->getOption('base-uri'), '/');
        return $baseUri . $parsedUrl['path'] . (isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : null);
    }

    /**
     * @param string $url
     * @return bool
     */
    private function validateUrl(string $url): bool
    {
        $validated = filter_var($url, FILTER_VALIDATE_URL);

        if (!$validated) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'])) {
            return false;
        }

        return true;
    }

    private function checkForStatusCodes(array $urls): array
    {
        $client = new Client([
            'headers' => ['user-agent' => $this->getUserAgent()],
            'http_errors' => false,
            'delay' => $this->getDelay(),
            'verify' => false,
        ]);

        $rows = [];
        $requestOptions = [
            'allow_redirects' => ['track_redirects' => $this->trackRedirects],
        ];

        $requests = function () use ($client, $urls, $requestOptions) {
            foreach ($urls as $index => $url) {
                yield $index => fn() => $client->sendAsync(
                    new \GuzzleHttp\Psr7\Request('GET', $url),
                    $requestOptions
                );
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => $this->getConcurrency(),
            'fulfilled' => function (ResponseInterface $response, int $index) use ($urls, &$rows) {
                $url = $urls[$index];
                $statusCode = $response->getStatusCode();

                foreach ($response->getHeader('X-Guzzle-Redirect-History') as $i => $redirectHistoryUrl) {
                    $redirectHistoryStatusCode = $response->getHeader('X-Guzzle-Redirect-Status-History')[$i];
                    $redirectRow = [$redirectHistoryUrl, $redirectHistoryStatusCode];
                    $this->writeResultRow($redirectHistoryUrl, $redirectHistoryStatusCode);
                    if ($this->csvWriter) {
                        $this->csvWriter->insertOne($redirectRow);
                    }
                }

                $row = [$url, $statusCode];
                $this->writeResultRow($url, $statusCode);
                $rows[] = $row;

                if ($this->csvWriter) {
                    $this->csvWriter->insertOne($row);
                }

                $this->progressBar?->advance();
            },
            'rejected' => function (\Exception $e, int $index) use ($urls, &$rows) {
                $url = $urls[$index];
                $statusCode = $e instanceof TooManyRedirectsException ? 'Redirect loop' : 'Error: ' . $e->getMessage();

                $row = [$url, $statusCode];
                $this->writeResultRow($url, $statusCode);
                $rows[] = $row;

                if ($this->csvWriter) {
                    $this->csvWriter->insertOne($row);
                }

                $this->progressBar?->advance();
            },
        ]);

        $pool->promise()->wait();

        return $rows;
    }

    private function getDelay(): int
    {
        return (int)($this->input->getOption('delay') ?? $this->defaultDelay);
    }

    private function getConcurrency(): int
    {
        return (int)($this->input->getOption('concurrency') ?? $this->defaultConcurrency);
    }

    /**
     * Initialize CSV writer and write headers
     * @param string $outputFile
     * @param bool $append
     * @throws CannotInsertRecord
     */
    private function initializeCsvWriter(string $outputFile, bool $append = false): void
    {
        if ($append) {
            $this->csvWriter = Writer::createFromPath($outputFile, 'a');
        } else {
            $this->csvWriter = Writer::createFromPath($outputFile, 'w');
            $this->csvWriter->insertOne(['url', 'status_code']);
        }
    }

    /**
     * Read URLs already present in the output CSV file
     * @param string $outputFile
     * @return array
     */
    private function getUrlsFromOutputFile(string $outputFile): array
    {
        try {
            $csv = Reader::createFromPath($outputFile);
            $csv->setHeaderOffset(0);
            $urls = [];
            foreach ($csv as $record) {
                if (isset($record['url'])) {
                    $urls[] = $record['url'];
                }
            }
            return $urls;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function writeResultRow(string $url, string|int $statusCode): void
    {
        $this->progressBar?->clear();
        $truncatedUrl = strlen($url) > 78 ? substr($url, 0, 75) . '...' : $url;
        $this->output->writeln(str_pad($truncatedUrl, 80) . ' ' . $statusCode);
        $this->progressBar?->display();
    }

    private function getUserAgent()
    {
        return $this->input->getOption('user-agent') ?? 'http-statuscode-checker ' . self::VERSION;
    }
}
