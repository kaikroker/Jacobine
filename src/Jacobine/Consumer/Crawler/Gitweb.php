<?php
/**
 * This file is part of the Jacobine package.
 *
 * (c) Andreas Grunwald <andygrunwald@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jacobine\Consumer\Crawler;

use Jacobine\Consumer\ConsumerAbstract;
use Jacobine\Component\Database\Database;
use Jacobine\Component\Crawler\CrawlerFactory;
use Buzz\Browser;

/**
 * Class Gitweb
 *
 * A consumer to crawl a Gitweb Server (https://git.wiki.kernel.org/index.php/Gitweb).
 * Every (git) project which is on the given Gitweb server will be stored in the git database table.
 * Further more a message to download the git repository will be created.
 *
 * Message format (json encoded):
 *  [
 *      url: URL of the Gitweb server. E.g. http://git.typo3.org/
 *      project: Project to be analyzed. Id of jacobine_project table
 *  ]
 *
 * Usage:
 *  php console jacobine:consumer Crawler\\Gitweb
 *
 * @package Jacobine\Consumer\Crawler
 * @author Andy Grunwald <andygrunwald@gmail.com>
 */
class Gitweb extends ConsumerAbstract
{

    /**
     * HTTP Client
     *
     * @var \Buzz\Browser
     */
    protected $remoteService;

    /**
     * Factory to create DOMCrawler
     *
     * @var \Jacobine\Component\Crawler\CrawlerFactory
     */
    protected $crawlerFactory;

    /**
     * Constructor to set dependencies
     *
     * @param Database $database
     * @param \Buzz\Browser $remoteService
     * @param \Jacobine\Component\Crawler\CrawlerFactory $crawlerFactory
     */
    public function __construct(
        Database $database,
        Browser $remoteService,
        CrawlerFactory $crawlerFactory
    ) {
        $this->setDatabase($database);
        $this->remoteService = $remoteService;
        $this->crawlerFactory = $crawlerFactory;
    }

    /**
     * Gets a description of the consumer
     *
     * @return string
     */
    public function getDescription()
    {
        return 'Crawls a Gitweb-Index page for Git-repositories';
    }

    /**
     * Initialize the consumer.
     * Sets the queue and routing key
     *
     * @return void
     */
    public function initialize()
    {
        parent::initialize();

        $this->setQueueOption('name', 'crawler.gitweb');
        $this->enableDeadLettering();

        $this->setRouting('crawler.gitweb');
    }

    /**
     * The logic of the consumer
     *
     * @param \stdClass $message
     * @throws \Exception
     * @return void
     */
    protected function process($message)
    {
        try {
            $content = $this->getContent($this->remoteService, $message->url);

        } catch (\Exception $e) {
            // At first this seems to be not so smart to catch an exception and throw a new one,
            // but i do this because i want to add the custom error message.
            // If there is a better way a pull request is welcome :)
            $context = [
                'url' => $message->url,
                'message' => $e->getMessage()
            ];
            $this->getLogger()->error('Reading gitweb frontend failed', $context);
            throw new \Exception('Reading gitweb frontend failed', 1398887554);
        }

        $crawler = $this->crawlerFactory->create($content);
        /* @var $crawler \Symfony\Component\DomCrawler\Crawler */

        $projectLinks = $crawler->filterXPath('//table[@class="project_list"]/tr/td[1]/a[@class="list"]');
        foreach ($projectLinks as $node) {
            $name = $node->nodeValue;

            $href = $node->getAttribute('href');
            $detailUrl = rtrim($message->url, '/') . $href;
            try {
                $content = $this->getContent($this->remoteService, $detailUrl);
            } catch (\Exception $e) {
                continue;
            }

            $detailCrawler = $this->crawlerFactory->create($content);
            /* @var $detailCrawler \Symfony\Component\DomCrawler\Crawler */
            $gitUrl = $detailCrawler->filterXPath(
                '//table[@class="projects_list"]/tr[@class="metadata_url"]/td[2]'
            )->text();

            $gitRecord = $this->getGitRepositoryFromDatabase($message->project, $gitUrl);
            if ($gitRecord === false) {
                $id = $this->insertGitRecord($message->project, $name, $gitUrl);
            } else {
                $id = $gitRecord['id'];
                $context = [
                    'project' => $message->project,
                    'git' => $gitUrl
                ];
                $this->getLogger()->info('Git record already exists', $context);
            }

            $this->addFurtherMessageToQueue($message->project, $id);
        }
    }

    /**
     * Returns the content of a web page
     *
     * @param \Buzz\Browser $browser
     * @param string $url
     * @return mixed
     * @throws \Exception
     */
    private function getContent($browser, $url)
    {
        $this->getLogger()->info('Requesting url', ['url' => $url]);
        $response = $browser->get($url);
        /** @var \Buzz\Message\Response $response */

        if ($response->getStatusCode() !== 200) {
            $context = [
                'url' => $url,
                'statusCode' => $response->getStatusCode()
            ];
            $this->getLogger()->error('URL is not crawlable', $context);
            $exceptionMessage = sprintf('URL "%s" is not crawlable', $url);
            throw new \Exception($exceptionMessage, 1369417933);
        }

        return $response->getContent();
    }

    /**
     * Adds new messages to queue system to download the git repository
     *
     * @param int $projectId
     * @param integer $id
     * @return void
     */
    private function addFurtherMessageToQueue($projectId, $id)
    {
        $message = [
            'project' => $projectId,
            'id' => $id
        ];

        $exchange = $this->container->getParameter('messagequeue.exchange');
        $this->getMessageQueue()->sendSimpleMessage($message, $exchange, 'download.git');
    }

    /**
     * Receives a single git record of the database
     *
     * @param int $projectId
     * @param string $repository
     * @return bool|array
     */
    private function getGitRepositoryFromDatabase($projectId, $repository)
    {
        $fields = ['id'];
        $where = [
            'project' => $projectId,
            'git' => $repository
        ];
        $rows = $this->getDatabase()->getRecords($fields, 'jacobine_git', $where, '', '', 1);

        $row = false;
        if (count($rows) === 1) {
            $row = array_shift($rows);
            unset($rows);
        }

        return $row;
    }

    /**
     * Inserts a new git record to database
     *
     * @param int $projectId
     * @param string $name
     * @param string $repository
     * @return string
     */
    private function insertGitRecord($projectId, $name, $repository)
    {
        $data = [
            'project' => $projectId,
            'name' => $name,
            'git' => $repository
        ];

        $this->getLogger()->info('Inserted new git record', $data);
        return $this->getDatabase()->insertRecord('jacobine_git', $data);
    }
}
