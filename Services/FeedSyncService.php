<?php

namespace Pumukit\Up2u\WebTVBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Person;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\Track;
use Pumukit\SchemaBundle\Services\FactoryService;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\SchemaBundle\Services\PersonService;
use Pumukit\SchemaBundle\Services\MultimediaObjectPicService;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 *  Service that iterates over the FeedSyncClientService responses, it processes them using the FeedProcesserService and then inserts/updates the object into the database.
 */
class FeedSyncService
{
    private $factoryService;
    private $tagService;
    private $personService;
    private $feedClientService;
    private $feedProcesserService;
    private $seriesRepo;
    private $mmobjRepo;
    private $tagRepo;
    private $personRepo;
    private $roleRepo;
    private $dm;

    private $providerRootTag;
    private $webTVTag;
    private $optWall; //If true, prints warnings.

    private $VIDEO_EXTENSIONS = array('mp4', 'm4v', 'm4b', 'flv');
    private $VIDEO_MIMETYPES = array('mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'm4b' => 'video/mp4', 'flv' => 'video/x-flv');
    private $AUDIO_EXTENSIONS = array('mp3', 'm4a', 'wav', 'ogg');
    private $AUDIO_MIMETYPES = array('mp3' => 'audio/mp3', 'm4a' => 'audio/m4a', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg');

    public function __construct(FactoryService $factoryService, TagService $tagService, PersonService $personService, MultimediaObjectPicService $mmsPicService, FeedSyncClientService $feedClientService,
                                FeedProcesserService $feedProcesserService, DocumentManager $dm, $dataFolder)
    {
        //Schema Services
        $this->factoryService = $factoryService;
        $this->tagService = $tagService;
        $this->personService = $personService;
        $this->mmsPicService = $mmsPicService;
        //Geant Sync Services
        $this->feedClientService = $feedClientService;
        $this->feedProcesser = $feedProcesserService;
        $this->dm = $dm;
        $this->dataFolder = $dataFolder;
        $this->init();
    }

    public function init()
    {
        $this->seriesRepo = $this->dm->getRepository('PumukitSchemaBundle:Series');
        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
        $this->tagRepo = $this->dm->getRepository('PumukitSchemaBundle:Tag');
        $this->personRepo = $this->dm->getRepository('PumukitSchemaBundle:Person');
        $this->roleRepo = $this->dm->getRepository('PumukitSchemaBundle:Role');
        $this->providerRootTag = $this->tagRepo->findOneByCod('PROVIDER');
        if (!isset($this->providerRootTag)) {
            $newTag = new Tag();
            $newTag->setParent($this->tagRepo->findOneByCod('ROOT'));
            $newTag->setCod('PROVIDER');
            $newTag->setMetatag(true);
            $newTag->setDisplay(false);
            $newTag->setTitle('Provider');
            $this->dm->persist($newTag);
            $this->dm->flush();
            $this->providerRootTag = $newTag;
        }
        $this->webTVTag = $this->tagRepo->findOneByCod('PUCHWEBTV');
        if (!isset($this->webTVTag)) {
            throw new FeedSyncException('Tag: PUCHWEBTV does not exists. Did you initialize the repository? (pumukit:init:repo)');
        }
        $this->optWall = false;
    }

    public function setFeedUrl($feedUrl)
    {
        $this->feedClientService->setFeedUrl($feedUrl);
    }

    public function blockUnsynced($output, $startTime, $tag = null)
    {
        $qb = $this->mmobjRepo->createQueryBuilder();
        if ($tag) {
            $qb->field('properties.geant_tag')->equals($tag);
        }
        $qb->field('status')->notEqual(MultimediaObject::STATUS_BLOQ)->field('properties.last_sync_date_v2')->lt($startTime->toDateTime()->format('c'));

        $output->writeln('...Blocking non-updated mmobjs...');

        $count = $qb->getQuery()->count();
        $qb->update()->multiple(true)->field('status')->set(MultimediaObject::STATUS_BLOQ);
        $qb->getQuery()
            ->execute();

        $output->writeln(sprintf('Number of blocked mmobjs: %s', $count));

        $qb = $this->mmobjRepo->createQueryBuilder();
        if ($tag) {
            $qb->field('properties.geant_tag')->equals($tag);
        }
        $qb->field('status')->notEqual(MultimediaObject::STATUS_BLOQ)->field('properties.geant_type')->in(array('external link', 'video flv'));

        $output->writeln('...Blocking flv and external link mmobjs...');

        $count = $qb->getQuery()->count();
        $qb->update()->multiple(true)->field('status')->set(MultimediaObject::STATUS_BLOQ);
        $qb->getQuery()
            ->execute();

        $output->writeln(sprintf('Number of blocked mmobjs: %s', $count));

        $output->writeln('...Blocking empty tags...');
        $this->providerRootTag = $this->tagRepo->findOneByCod('PROVIDER'); // Necessary after the DocumentManager::clear
        $providerTags = $this->providerRootTag->getChildren();
        $count = 0;
        foreach ($providerTags as $tag) {
            $series = $this->seriesRepo->findOneBySeriesProperty('geant_provider', $tag->getCod());
            if ($series) {
                $numMmobjs = $this->mmobjRepo->createBuilderWithSeries($series)
                           ->field('status')->notEqual(MultimediaObject::STATUS_PROTOTYPE)
                           ->count()
                           ->getQuery()->execute();

                if ($numMmobjs > 0) {
                    $tag->setProperty('empty', false);
                } else {
                    if (!$tag->getProperty('empty', false)) {
                        $tag->setProperty('empty', true);
                        ++$count;
                    }
                }
            } else {
                if (!$tag->getProperty('empty', false)) {
                    $tag->setProperty('empty', true);
                    ++$count;
                }
            }
            $this->dm->persist($tag);
            $this->dm->flush();
        }
        $output->writeln(sprintf('Number of blocked providers: %s', $count));
        $output->writeln("-----------\nblockUnsynced() finished");
    }

    public function sync($output, $limit = 0, $optWall = false, $provider = null, $verbose = false, $setProgressBar = false, $tag = null)
    {
        $this->optWall = $optWall;
        $this->verbose = $verbose;
        $loggedResults = array();
        $time_started = microtime(true);
        $count = 0;
        $total = $this->feedClientService->getFeedTotal($provider);
        if ($limit == 0 || $limit > $total) {
            $limit = $total;
        }
        $progressBar = null;
        if ($setProgressBar) {
            $progressBar = new ProgressBar($output, $total);
            $progressBar->setFormat("<comment>%message%</comment>\n%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%");
            $progressBar->setMessage(' Syncing with Geant Feed...');
            $progressBar->start();
        }
        $terenaGenerator = $this->feedClientService->getFeed($limit, $provider);
        $lastSyncDate = new \MongoDate();
        foreach ($terenaGenerator as $terena) {
            ++$count;
            if ($verbose) {
                echo sprintf("\nImporting object: id: %s\n", $terena['identifier']);
            }
            if ($count % 10 == 0) {
                $this->showProgressEstimateDuration($time_started, $count, $total, $progressBar);
            }

            $providerCode = $terena['set'];
            //Initializes the provider log.
            if (!isset($loggedResults[$providerCode])) {
                $loggedResults[$providerCode] = array(
                    'total' => 0,
                    'failed' => array(), );
            }
            //We increase the number of objects on the log for this repository:
            ++$loggedResults[$providerCode]['total'];

            try {
                $parsedTerena = $this->feedProcesser->process($terena);
            } catch (FeedSyncException $e) {
                if (isset($progressBar)) {
                    //Log exception error.
                    $progressBar->clear();
                    echo sprintf("\nPARSING GENERATOR EXCEPTION: -Message: %s \n", $e->getMessage());
                    echo "\n";
                    $progressBar->display();
                } else {
                    echo sprintf("\nPARSING GENERATOR EXCEPTION: -Message: %s \n", $e->getMessage());
                }
                $loggedResults[$providerCode]['failed'][] = $e->getMessage();
                continue;
            }

            try {
                $this->syncMmobj($parsedTerena, $lastSyncDate, $tag);
            } catch (FeedSyncException $e) {
                if (isset($progressBar)) {
                    $progressBar->clear();
                    echo sprintf("\nSYNC GENERATOR EXCEPTION: \n-Message: %s \n----", $e->getMessage());
                    echo "\n";
                    $progressBar->display();
                } else {
                    echo sprintf("\nSYNC GENERATOR EXCEPTION: \n-Message: %s \n----", $e->getMessage());
                }
                $loggedResults[$providerCode]['failed'][] = $e->getMessage();
                continue;
            }

            if ($count >= $limit) {
                $this->dm->flush();
                $this->dm->clear();
                break;
            }
            if ($count % 50 == 0) {
                $this->dm->flush();
                $this->dm->clear();
            }
        }
        $this->dm->flush();
        $this->dm->clear();
        if (isset($progressBar)) {
            $progressBar->finish();
        }

        $this->printLoggedResults($loggedResults, $output);

        return $lastSyncDate;
    }

    public function syncMmobj($parsedTerena, \MongoDate $lastSyncDate = null, $tag = null)
    {
        static $tagCache = array();

        $providerTitle = sprintf('%s - %s', $tag, $parsedTerena['provider']);

        if (!isset($lastSyncDate)) {
            $lastSyncDate = new \MongoDate();
        }
        $factory = $this->factoryService;
        $mmobj = $this->mmobjRepo->createQueryBuilder()
               ->field('properties.geant_id')
               ->equals($parsedTerena['identifier'])
               ->getQuery()
               ->getSingleResult();

        $series = $this->seriesRepo->createQueryBuilder()
                ->field('properties.geant_provider')
                ->equals($parsedTerena['provider'])
                ->getQuery()
                ->getSingleResult();
        if (!isset($series)) {
            $series = $factory->createSeries();
            $series->setProperty('geant_provider', $parsedTerena['provider']);
            $series->setProperty('geant_repository', $tag);
            $series->setTitle($providerTitle);
            $this->dm->persist($series);
            $this->dm->flush();
        }

        //We assume the 'provider' property of a feed won't change for the same Geant Feed Resource.
        //If it changes, the mmobj would keep it's original provider.
        if (!isset($mmobj)) {
            $mmobj = $factory->doCreateMultimediaObject($series, false);
            $mmobj->setProperty('geant_id', $parsedTerena['identifier']);
            $mmobj->setProperty('geant_tag', $tag);
            $mmobj->setProperty('feed_updated_date', $parsedTerena['lastUpdateDate']); //to delte use v2
            $mmobj->setPropertyAsDateTime('feed_updated_date_v2', $parsedTerena['lastUpdateDate']);
            //Add 'provider' tag

            $tagCod = $parsedTerena['provider'];

            if (isset($tagCache[$tagCod])) {
                $providerTag = $tagCache[$tagCod];
            } elseif ($providerTag = $this->tagRepo->findOneByCod($parsedTerena['provider'])) {
                $tagCache[$tagCod] = $providerTag;
            } else {
                $providerTag = new Tag();
                $providerTag->setParent($this->providerRootTag);
                $providerTag->setCod($parsedTerena['provider']);
                $providerTag->setTitle($providerTitle);
                $providerTag->setDisplay(true);
                $providerTag->setMetatag(false);
                $providerTag->setProperty('geant_provider', $parsedTerena['provider']);
                $providerTag->setProperty('geant_repository', $tag);
                $this->dm->persist($providerTag);
                $tagCache[$tagCod] = $providerTag;
            }
            $this->tagService->addTagToMultimediaObject($mmobj, $providerTag->getId(), false);
        }

        $mmobj->setProperty('feed_updated_date', $parsedTerena['lastUpdateDate']); //to delte use v2
        $mmobj->setPropertyAsDateTime('feed_updated_date_v2', $parsedTerena['lastUpdateDate']);
        $mmobj->setProperty('last_sync_date', $lastSyncDate); //to delte use v2
        $mmobj->setPropertyAsDateTime('last_sync_date_v2', $lastSyncDate->toDateTime());
        $series->setProperty('last_sync_date', $lastSyncDate); //to delte use v2
        $series->setPropertyAsDateTime('last_sync_date_v2', $lastSyncDate->toDateTime());
        //PUBLISH
        $mmobj->setStatus(MultimediaObject::STATUS_PUBLISHED);
        $this->tagService->addTagToMultimediaObject($mmobj, $this->webTVTag->getId(), false);

        //METADATA
        $this->syncMetadata($mmobj, $parsedTerena);

        //TAGS
        $this->syncTags($mmobj, $parsedTerena);

        //PEOPLE
        $this->syncPeople($mmobj, $parsedTerena);

        //TRACK
        $this->syncTrack($mmobj, $parsedTerena);

        //THUMBNAIL
        $this->syncThumbnail($mmobj, $parsedTerena);

        //Errors

        if ($parsedTerena['geantErrors']) {
            $mmobj->setProperty('geant_errors', $parsedTerena['geantErrors']);
        } else {
            $mmobj->setProperty('geant_errors', null);
        }

        //SAVE CHANGES
        $this->dm->persist($mmobj);
        $this->dm->persist($series);
    }

    public function syncMetadata(MultimediaObject $mmobj, $parsedTerena)
    {
        $mmobj->setTitle($parsedTerena['title']);
        $mmobj->setDescription($parsedTerena['description']);
        foreach ($parsedTerena['keywords'] as $keyword) {
            $mmobj->setKeyword($keyword);
        }
        $mmobj->setLicense($parsedTerena['license']);
        $mmobj->setCopyright($parsedTerena['copyright']);
        $mmobj->setRecordDate($parsedTerena['record_date']);
        $mmobj->setDuration($parsedTerena['duration']);
    }

    public function syncTags(MultimediaObject $mmobj, $parsedTerena)
    {
        foreach ($parsedTerena['tags'] as $parsedTag) {
            $parsedTag = strval($parsedTag); //Sometimes they are ints.
            $tag = $this->tagRepo->findOneByCod($parsedTag); //First we search by code on the database (it should be iTunesU, but could be other)

            if (!isset($tag)) {  //Second we search by title on the database (again, it should be iTunesU, but could be other)
                $tag = $this->tagRepo->findOneByTitle($parsedTag);
            }

            if (!isset($tag)) {  //Now we start getting tricky. We search the cod, but adding 'U' (It should be UNESCO)
                $tag = $this->tagRepo->findOneByCod(sprintf('U%s', $parsedTag));
            }

            if (!isset($tag)) { //If we can't find it here, all hope is lost. We log it and continue.
                if ($this->optWall) {
                    echo "\n".sprintf('Warning: The tag with cod/title %s from the Feed ID:%s does not exist on PuMuKIT', $parsedTag, $parsedTerena['identifier']);
                }
                continue;
            }

            //If the tag turned out to be from UNESCO, we try to add the iTunesU mapped tag
            if ($tag->isDescendantOfByCod('UNESCO')) {
                $mappedItunesTags = $this->feedProcesser->mapCodeToItunes(sprintf('U%s', substr($parsedTag, 0, 3)));
                foreach ($mappedItunesTags as $itunesTag) {
                    $iTag = $this->tagRepo->findOneByCod($itunesTag);
                    if (!isset($iTag)) {
                        throw new FeedSyncException(sprintf('Error! The parsed iTunes tag with code: %s  doesnt exists on PuMuKIT. Did you initialize the iTunes repo?', $itunesTag));
                    }
                    $this->tagService->addTagToMultimediaObject($mmobj, $iTag->getId(), false);
                }
            }
            $this->tagService->addTagToMultimediaObject($mmobj, $tag->getId(), false);
        }
    }

    public function syncPeople(MultimediaObject $mmobj, $parsedTerena)
    {
        foreach ($parsedTerena['people'] as $contributor) {
            $person = $this->personRepo->findOneByName($contributor['name']);
            if (!isset($person)) { //If the person doesn't exist, create a new one.
                $person = new Person();
                $person->setName($contributor['name']);
                $this->personService->savePerson($person);
            }

            $role = $this->roleRepo->findOneByCod($contributor['role']);
            if (!isset($role)) {  //Workaround for PuMuKIT. The 'Cod' field is not consistent, some are lowercase, some are ucfirst
                $role = $this->roleRepo->findOneByCod(ucfirst($contributor['role']));
            }

            if (!isset($role)) { //If the role doesn't exist, use 'Participant'.
                $role = $this->roleRepo->findOneByCod('Participant'); // <-- This cod is ucfirst, but others are lowercase.
            }

            $this->personService->createRelationPerson($person, $role, $mmobj, false);
        }
    }

    public function syncTrack(MultimediaObject $mmobj, $parsedTerena)
    {
        $url = $parsedTerena['track_url'];
        $urlParsed = parse_url($url);
        //TODO if track_url add a error.
        $urlExtension = isset($urlParsed['path']) ?
                      pathinfo($urlParsed['path'], PATHINFO_EXTENSION) :
                      null;
        $track = $mmobj->getTrackWithTag('geant_track');
        if (!isset($track)) {
            $track = new Track();
            $mmobj->addTrack($track);
        }
        $track->addTag('geant_track');
        $track->setLanguage($parsedTerena['language']);
        $track->setDuration($parsedTerena['duration']);
        $track->setPath($url);
        $track->setUrl($url);

        $mmobj->setProperty('geant_track_format', $parsedTerena['track_format']);

        $format = explode('/', $parsedTerena['track_format']);
        $formatType = isset($format[0]) ? $format[0] : null;
        $formatExtension = isset($format[1]) ? $format[1] : null;

        $mmobj->setProperty('opencast', false); //Workaround to prevent editing the Schema Filter for now.

        # Workaround: The CMAR type is incorrectly set as 'video/mp4', when the resource is an html video.
        if(false !== strpos($url, 'https://tv.campusdomar.es')){
            $formatType = 'iframe';
        }

        if (($formatType == 'video' && in_array($formatExtension, $this->VIDEO_EXTENSIONS)) || in_array($urlExtension, $this->VIDEO_EXTENSIONS)) {
            $track->addTag('display');
            $track->setOnlyAudio(false);
            $mmobj->setProperty('redirect', false);
            $mmobj->setProperty('iframeable', false);

            if (strpos($url, '.flv')) {
                $mmobj->setProperty('geant_type', 'video flv');
                $mmobj->setStatus(MultimediaObject::STATUS_BLOQ);
            } else {
                $mmobj->setProperty('geant_type', 'video');
            }

            $extension = ($formatType == 'video' && in_array($formatExtension, $this->VIDEO_EXTENSIONS)) ? $formatExtension : $urlExtension;
            $mimeType = isset($this->VIDEO_MIMETYPES[$extension]) ? $this->VIDEO_MIMETYPES[$extension] : '';
            $track->setMimeType($mimeType);
            $track->setVcodec('h264');
        } elseif (($formatType == 'audio' && in_array($formatExtension, $this->AUDIO_EXTENSIONS)) || in_array($urlExtension, $this->AUDIO_EXTENSIONS)) {
            $track->addTag('display');
            $track->setOnlyAudio(true);
            $mmobj->setProperty('redirect', false);
            $mmobj->setProperty('iframeable', false);
            $mmobj->setProperty('geant_type', 'audio');
            $extension = ($formatType == 'audio' && in_array($formatExtension, $this->AUDIO_EXTENSIONS)) ? $formatExtension : $urlExtension;
            $mimeType = isset($this->AUDIO_MIMETYPES[$extension]) ? $this->AUDIO_MIMETYPES[$extension] : '';
            $track->setMimeType($mimeType);
        } else {
            //We try to create an embed Url. If we can't, it returns false and we'll redirect instead. (When other repositories provides more embedded urls we will change this)
            $embedUrl = $this->feedProcesser->getEmbedUrl($url);

            if ($embedUrl) {
                $mmobj->setProperty('opencast', true); //Workaround to prevent editing the Schema Filter for now.
                $mmobj->setProperty('iframeable', true);
                $mmobj->setProperty('redirect', false);
                $mmobj->setProperty('iframe_url', $embedUrl);
                $mmobj->setProperty('geant_type', 'external video player');
            } else {
                $mmobj->setProperty('opencast', true); //Workaround to prevent editing the Schema Filter for now.
                $mmobj->setProperty('redirect', true);
                $mmobj->setProperty('iframeable', false);
                $mmobj->setProperty('redirect_url', $url);
                $mmobj->setProperty('geant_type', 'external link');
                $mmobj->setStatus(MultimediaObject::STATUS_BLOQ);
            }
        }
        $this->dm->persist($track);
        $track->addTag('geant_track');
    }

    public function syncThumbnail(MultimediaObject $mmobj, $parsedTerena)
    {
        $url = $parsedTerena['thumbnail'];
        $pics = $mmobj->getPics();
        if (0 === count($pics)) {
            $mmobj = $this->mmsPicService->addPicUrl($mmobj, $url, false);
        } else {
            foreach ($pics as $pic) {
                break;
            } //Woraround to get the first element.
            $pic->setUrl($url);
            $this->dm->persist($pic);
        }
    }

    /**
     * Prints on screen an estimated duration of the script and statistics about its execution.
     */
    //TODO USE Symfony Progress Bar: http://symfony.com/doc/current/components/console/helpers/progressbar.html
    protected function showProgressEstimateDuration($time_started, $processed, $total, $progressBar = null)
    {
        $now = microtime(true);
        $origin = $time_started;
        $elapsed_sec = (float) ($now - $origin);
        $eta_sec = ($total * $elapsed_sec) / $processed;
        $eta_min = $eta_sec / 60;
        $elapsed_min = $elapsed_sec / 60;
        $processed_min = (int) ($processed / $elapsed_min);
        if (isset($progressBar)) {
            $progressBar->setProgress($processed);
        } else {
            echo "\nTerena entry ".$processed.' / '.$total."\n";
            echo 'Elapsed time: '.sprintf('%.2F', $elapsed_min).
                                 ' minutes - estimated: '.sprintf('%.2F', $eta_min).
                                 ' minutes. Speed: '.$processed_min." terenas / minute.\n";
        }
    }

    public function syncRepos($output, $optWall, $show_bar, $reposDir = null)
    {
        if (!$reposDir) {
            $reposDir = $this->dataFolder->locateResource('@PumukitUp2uWebTVBundle/Resources/data/repos_data');
        }
        $providerTag = $this->tagRepo->findOneBy(array('cod' => 'PROVIDER'));
        if (!$providerTag) {
            $output->writeln('<error>PROVIDER tag does not exist</error>');

            return;
        }
        $providers = $providerTag->getChildren();
        $defaultThumbnail = 'bundles/pumukitup2uwebtv/images/repositories/default_picture.png';

        //Progress bar init.
        $total = count($providers);
        if (0 == $total) {
            $output->writeln('<error>Not providers in DDBB</error>');

            return;
        }
        $progressBar = new ProgressBar($output, $total);
        $progressBar->setFormat("<comment>%message%</comment>\n%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%");
        $progressBar->setMessage(' Loading repos metadata');
        $progressBar->start();

        foreach ($providers as $provider) {
            $fileDir = $reposDir.'/'.$provider->getCod().'.json';
            if (file_exists($fileDir)) {
                $str = file_get_contents($fileDir);
                $providerData = json_decode($str, true);
                $provider->setProperty('description', $providerData['description']);
                $providerTitle = sprintf('%s - %s', $provider->getProperty('geant_repository'), $providerData['title']);
                $provider->setTitle($providerTitle);
                if (isset($providerData['thumbnail_url']) && !empty($providerData['thumbnail_url'])) {
                    $thumbnailUrl = $this->parseThumbnailUrl($providerData['thumbnail_url']);
                } else {
                    $thumbnailUrl = $defaultThumbnail;
                }
                $provider->setProperty('thumbnail_url', $thumbnailUrl);
            } else {
                $provider->setProperty('description', '');
                $provider->setProperty('thumbnail_url', $defaultThumbnail);
            }
            $this->dm->persist($provider);
            $progressBar->advance();
        }
        $progressBar->finish();
        $output->writeln("\nALL LOADED\n");
        $this->dm->flush();
    }

    protected function parseThumbnailUrl($thumbUrl)
    {
        if (strpos($thumbUrl, 'http') !== false) {
            return $thumbUrl;
        } else {
            return '/bundles/pumukitup2uwebtv/images/repositories/'.$thumbUrl;
        }
    }

    /**
     * Helper function that prints the results logged on the $loggedResults array.
     */
    protected function printLoggedResults($loggedResults, $output)
    {
        $output->writeln('---------');
        $output->writeln('-------- IMPORTED OBJECTS STATISTICS --------');
        $output->writeln('Total Providers: '.count($loggedResults));
        $allObjects = 0;
        foreach ($loggedResults as $name => $result) {
            $failedNumber = count($result['failed']);
            $totalNumber = $result['total'];
            $successNumber = $totalNumber - $failedNumber;
            $successPercentage = ($successNumber / $totalNumber) * 100;
            $output->writeln(sprintf(' - %s: %01.2f%% of Objects were added.  (%s/%s Objects)', $name, $successPercentage, $successNumber, $totalNumber));
        }
        $output->writeln(' -------- DETAILED RESULTS: --------');
        foreach ($loggedResults as $name => $result) {
            $output->writeln(' .......... '.$name.' .......... ');
            foreach ($result['failed'] as $error) {
                $output->writeln(sprintf(' - %s: ', $error));
            }
            $output->writeln(' ................. ');
        }
        $output->writeln('-------------');
    }
}
