<?php

namespace buibr\xmlepg;


use XMLReader;
use SimpleXMLElement;
use RuntimeException;
use DateTimeZone;

class EpgParser {

	//	Source datas.
	private $file;
	private $url;
	private $content;

	//	for temporary file if from content or from url.
	private $isTemp;
	public $temp_dir = '/tmp';	//unix, change this for windows.

	//	channel settings
	private $channels;
	private $channels_groupby = '@id';

	//	programmes settings.
	private $epgdata;
	private $epgdata_groupby = '@id';

	//	filter
	private $channelfilter = [];
	private $ignoreDescr = [];

	//	zone.
	private $targetTimeZone;

	//	callbacks
	public $onError;

	public function __construct() {
		$this->targetTimeZone = \date_default_timezone_get();
	}

	/**
	 * @param mixed $file`
	 */
	public function setFile($file): void {
		$this->file = $file;
	}

	/**
	 * @param mixed $url - url 
	 */
	public function setUrl($url): void {
		$this->url = $url;
	}

	/**
	 * @param mixed $content = xml parsed string. 
	 */
	public function setContent($content): void {
		$this->content = $content;
	}

	/**
	 * @param mixed $channelfilter
	 */
	public function setChannelfilter($channelfilter): void {
		$this->channelfilter[$channelfilter] = 1;
	}

	/**
	 * @param string $descr
	 */
	public function setIgnoreDescr(string $descr): void {
		$this->ignoreDescr[$descr]=1;
	}

	/**
	 * @param mixed $targetTimeZone
	 */
	public function setTargetTimeZone($targetTimeZone): void {
		$this->targetTimeZone = $targetTimeZone;
	}

	/**
	 * Set group by for channels must be channels atribute.
	 * @param $group - channel will be grouped with. must be @id or pgram attribute. 
	 */
	public function setChannelGroup($group){
		$this->channels_groupby = $group;
	}

	/**
	 * Set group by for channels must be channels atribute.
	 * 	@id = array index starting from 0
	 * @param $group - programes will be grouped with. must be @id or pgram attribute.
	 */
	public function setProgrammGroup($group){
		$this->epgdata_groupby = $group;
	}

	/**
	 * Parse the date from string in posible formats.
	 */
	public function getDate( string $date ){

		try
		{
			$dt		= \DateTime::createFromFormat('YmdHis P', $date,new DateTimeZone('UTC'));
			$dt->setTimezone( new DateTimeZone($this->targetTimeZone) );
			return	$dt->format('Y-m-d H:i:s');
		}
		catch( \Exception $e ){}
		catch( \Error $e ){}

		try
		{
			$dt		= \DateTime::createFromFormat('YmdHis', $date, new DateTimeZone('UTC'));
			$dt->setTimezone( new DateTimeZone($this->targetTimeZone) );
			return	$dt->format('Y-m-d H:i:s');
		}
		catch( \Exception $e ){}
		catch( \Error $e ){}


		try
		{
			$ex = explode(' ', $date);
			$sd = $ex[0];
			$ed = $ex[1];
			
			if(strlen($sd) == 13) {
				$sd = "{$sd}0";
			}
			
			$date = $sd." ".$ed;

			$dt		= \DateTime::createFromFormat('YmdHis P', $date,new DateTimeZone('UTC'));
			$dt->setTimezone( new DateTimeZone($this->targetTimeZone) );
			return	$dt->format('Y-m-d H:i:s');
		}
		catch( \Exception $e ){}
		catch( \Error $e ){}

		
		return null;
	}

	/**
	 * @param $descr
	 *
	 * @return string
	 */
	private function filterDescr($descr): string {
		if (array_key_exists($descr,$this->ignoreDescr)) {
			return '';
		}
		return $descr;
	}

	/**
	 * @return mixed
	 */
	public function getChannels() {
		return $this->channels;
	}

	/**
	 * @return array
	 */
	public function getEpgdata() {
		return $this->epgdata;
	}

	/**
	 * 
	 */
	public function resetChannelfilter(): void {
		$this->channelfilter = [];
	}

	/**
	 * 
	 */
	private function channelMatchFilter(string $channel): bool {
		return array_key_exists($channel, $this->channelfilter);
	}

	/**
	 * @throws \RuntimeException
	 * @throws \Exception
	 */
	public function parseFile(): void {

		if (!$this->file) {
			throw new \RuntimeException('missing file: please use setFile before parse');
		}

		if (!file_exists($this->file)) {
			throw new \RuntimeException('file does not exists: ' . $this->file);
		}

		//	
		$xml = new XMLReader();
		
		//	compress.zlib://'
		$xml->open($this->file);

		/** @noinspection PhpStatementHasEmptyBodyInspection */
		/** @noinspection LoopWhichDoesNotLoopInspection */
		/** @noinspection MissingOrEmptyGroupStatementInspection */
		while ($xml->read() && $xml->name !== 'channel') {
		}

		$i = 0;
		while ($xml->name === 'channel') {
			$element 	= new SimpleXMLElement($xml->readOuterXML());

			/** @noinspection	PhpUndefinedFieldInspection */
			$group_by	= $this->channels_groupby === '@id' ? (@$i++) : (string)$element->attributes()->{$this->epgdata_groupby};

			//	se the id
			$channel_id = $group_by?:1;

			/** @noinspection PhpUndefinedFieldInspection */
			$this->channels[$channel_id] = [
				'id'=>(string)$element->attributes()->id,
				'display-name'=>(string)$element->{'display-name'},
				'url'=>(string)$element->{'url'},
				'email'=>(string)$element->{'email'},
				'icon'=> null,
			];

			if(isset($element->{'icon'}) && $element->{'icon'} instanceof SimpleXMLElement)
			{
				$attributes = $element->{'icon'}->attributes();
				$pathinfo 	= pathinfo($attributes->src);

				if(empty($attributes->src)){
					$this->channels[$channel_id]['icon'] = (string)$element->{'icon'};
					$xml->next('channel');
					unset($element);
				} 
				elseif(!filter_var($attributes->src, FILTER_VALIDATE_URL)){
					$this->channels[$channel_id]['icon'] = (string)$element->{'icon'};
					$xml->next('channel');
					unset($element);
				}
				elseif(empty($pathinfo['extension'])){
					$this->channels[$channel_id]['icon'] = (string)$element->{'icon'};
					$xml->next('channel');
					unset($element);
					continue;
				}
				else {
					$this->channels[$channel_id]['icon'] = (string)$attributes->src;
				}
			}

			$xml->next('channel');
			unset($element);
		}

		$xml->close();
		$xml->open($this->file);

		/** @noinspection PhpStatementHasEmptyBodyInspection */
		/** @noinspection LoopWhichDoesNotLoopInspection */
		/** @noinspection MissingOrEmptyGroupStatementInspection */
		while ($xml->read() && $xml->name !== 'programme') 
		{
		}

		while ($xml->name === 'programme') 
		{
			$element = new SimpleXMLElement($xml->readOuterXML());

			/** @noinspection PhpUndefinedFieldInspection */
			if ( !\count($this->channelfilter) || (\count($this->channelfilter) && $this->channelMatchFilter((string)$element->attributes()->channel))) 
			{

				/** @noinspection 	PhpUndefinedFieldInspection */
				$startString		= $this->getDate( (string)$element->attributes()->start );
				
				/** @noinspection	PhpUndefinedFieldInspection */
				$stopString			= $this->getDate( (string)$element->attributes()->stop );

				/** @noinspection	PhpUndefinedFieldInspection */
				$grouper			= $this->epgdata_groupby === '@id' ? (@$i++) : (string)$element->attributes()->{$this->epgdata_groupby};

				/** @noinspection PhpUndefinedFieldInspection */
				$this->epgdata[$grouper?:0] = [
					'start'       => $startString,
					'start_raw'   => (string)$element->attributes()->start,
					'channel'     => (string)$element->attributes()->channel,
					'stop'        => $stopString,
					'stop_raw'    => (string)$element->attributes()->stop,
					'title'       => (string)$element->title,
					'sub-title'   => (string)$element->{'sub-title'},
					'desc'        => $this->filterDescr((string)$element->desc),
					'date'        => (int)(string)$element->date,
					'category'	  => (string)$element->category,
					'credits'     => (string)$element->credits,
					'country'     => (string)$element->country,
					'icon'        => (string)$element->icon,
					'episode-num' => (string)$element->{'episode-num'},
				];

			}

			$xml->next('programme');
			unset($element);
		}

		$xml->close();

		if($this->isTemp) {
			@unlink( $this->file );
		}

	}

	/**
	 * @throws \RuntimeException
	 * @throws \Exception
	 */
	public function parseUrl(): void {

		if (!$this->url) {
			throw new \RuntimeException('Url missing: please use setUrl before parseUrl');
		}

		if (!filter_var($this->url, FILTER_VALIDATE_URL)) {
			throw new \RuntimeException('Url invalid: ' . $this->url);
		}

		$this->content = $this->file_get_contents_curl( $this->url );
		$this->checkXml();
		$this->saveTemp(); // will save the file.
		$this->parseFile();

	}

	public function file_get_contents_curl($url, $retries=5)
	{
		$ua = 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/48.0.2564.82 Safari/537.36';

		if (extension_loaded('curl') === true) {
			$ch = curl_init();

			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
			curl_setopt($ch, CURLOPT_USERAGENT, $ua);
			curl_setopt($ch, CURLOPT_FAILONERROR, TRUE);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
			curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
			curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

			$result = curl_exec($ch);
			curl_close($ch);
		}
		else
			$result = file_get_contents($url);

		if (empty($result) === true) {
			$result = false;

			if ($retries >= 1) {
				sleep(1);
				return file_get_contents_curl($url, --$retries);
			}
		}

		return $result;
	}

	/**
	 * @throws \RuntimeException
	 * @throws \Exception
	 */
	public function parseContent(): void {

		if (!$this->content) {
			throw new \RuntimeException('Url missing: please use setUrl before parseUrl');
		}

		$this->checkXml();
		$this->saveTemp(); // will save the file.
		$this->parseFile();

	}


	/**
	 * Save content to temp file from ulr or from content.
	 */
	public function saveTemp( ) : void
	{
		if(empty($this->temp_dir)){
			$this->temp_dir = '/tmp/';
		}

		$length = 15;
		$filename = substr(str_shuffle(str_repeat($x='0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length/strlen($x)) )),1,$length);
		
		$this->file = $this->temp_dir . '/' . $filename . '.xml';

		if(!\file_put_contents($this->file, $this->content)){
			throw new \RuntimeException("Writing to {$this->file} is not possible.");
		}

		$this->isTemp	= true;
		$this->content	= null;
	}


	/**
	 * Check content of response from xml f is xml
	 * @throws \RuntimeException
	 */
	public function checkXml(){
		libxml_use_internal_errors(true);
		$doc = simplexml_load_string($this->content);
		if (!$doc) {
			$errors = libxml_get_errors();
			@$errors = $errors[0];

			throw new \RuntimeException("Content of this request its not XML: $errors");
		}
	}


}