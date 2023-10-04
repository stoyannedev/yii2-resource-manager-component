<?php
/**
 * @link http://2amigos.us
 * @copyright Copyright (c) 2013 2amigOS! Consulting Group LLC
 * @license http://www.opensource.org/licenses/bsd-license.php New BSD License
 */
namespace dosamigos\resourcemanager;

use Aws\S3\S3Client;
use GuzzleHttp\Exception\RequestException;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use Yii;

/**
 *
 * AmazonS3ResourceManager handles resources to upload/uploaded to Amazon AWS
 *
 * @author Antonio Ramirez <amigo.cobos@gmail.com>
 * @link http://www.ramirezcobos.com/
 * @link http://www.2amigos.us/
 */
class AmazonS3ResourceManager extends Component implements ResourceManagerInterface
{

	/**
     * @var string Amazon version
     */
    public $version;
	/**
	 * @var string Amazon access key
	 */
	public $key;
	/**
	 * @var string Amazon secret access key
	 */
	public $secret;
	/**
     * @var string Amazon region
     */
    public $region;
	/**
	 * @var string Amazon Bucket
	 */
	public $bucket;
	/**
	 * @var \Aws\S3\S3Client
	 */
	private $_client;

	/**
	 * @var boolean V4 signature
	 */
	public $enableV4=false;

	/**
	 * @inheritdoc
	 */
	public function init()
	{
		foreach (['key','secret', 'region', 'bucket'] as $attribute) {
			if ($this->$attribute === null) {
				throw new InvalidConfigException(strtr('"{class}::{attribute}" cannot be empty.', [
					'{class}' => static::class,
					'{attribute}' => '$' . $attribute
				]));
			}
		}
		parent::init();
	}

	/**
	 * Saves a file
	 * @param \yii\web\UploadedFile $file the file uploaded. The [[UploadedFile::$tempName]] will be used as the source
	 * file.
	 * @param string $name the name of the file
	 * @param array $options extra options for the object to save on the bucket. For more information, please visit
	 * [[http://docs.aws.amazon.com/aws-sdk-php/latest/class-Aws.S3.S3Client.html#_putObject]]
	 * @return \Guzzle\Service\Resource\Model
	 */
	public function save($file, $name, $options = [])
	{
		$options = ArrayHelper::merge([
			'Bucket' => $this->bucket,
			'Key' => $name,
			'SourceFile' => $file->tempName,
			'ACL' => 'public-read' // default to ACL public read
		], $options);

		return $this->getClient()->putObject($options);
	}

	/**
	 * Removes a file
	 * @param string $name the name of the file to remove
	 * @return boolean
	 */
	public function delete($name)
	{
		$result = $this->getClient()->deleteObject([
			'Bucket' => $this->bucket,
			'Key' => $name
		]);

		return $result['DeleteMarker'];
	}

	/**
	 * Checks whether a file exists or not. This method only works for public resources, private resources will throw
	 * a 403 error exception.
	 * @param string $name the name of the file
	 * @return boolean
	 */
	public function fileExists($name)
	{
		$http = new \GuzzleHttp\Client();
		try {
			$response = $http->get($this->getUrl($name));
		} catch(RequestException $e) {
			return false;
		}
		return $response->getStatusCode() == 200;
	}

	/**
	 * Returns the url of the file or empty string if the file does not exists.
	 * @param string $name the key name of the file to access
	 * @param mixed $expires The time at which the URL should expire
	 * @return string
	 */
	public function getUrl($name, $expires = NULL)
	{
		return $this->getClient()->getObjectUrl($this->bucket, $name, $expires);
	}
	
	/**
	 * Delete all objects that match a specific key prefix.
	 * @param string $prefix delete only objects under this key prefix
	 * @return type
	 */
	public function deleteMatchingObjects($prefix) {
		return $this->getClient()->deleteMatchingObjects($this->bucket, $prefix);
	}

	/**
	 * Return the full path a file names only (no directories) within s3 virtual "directory" by treating s3 keys as path names.
	 * @param string $directory the prefix of keys to find
	 * @return array of ['path' => string, 'name' => string, 'type' => string, 'size' => int]
	 */
	public function listFiles($directory) {
		$files = [];
		
		$iterator = $this->getClient()->getIterator('ListObjects', [
			'Bucket' => $this->bucket,
			'Prefix' => $directory,
		]);

		foreach ($iterator as $object) {
			// don't return directories
			if(substr($object['Key'], -1) != '/') {
				$file = [
					'path' => $object['Key'],
					'name' => substr($object['Key'], strrpos($object['Key'], '/' ) + 1),
					'type' => $object['StorageClass'],
					'size' => (int)$object['Size'],
				];
				$files[] = $file;
			}
		}
		
		return $files;
	}

	/**
	 * Returns a S3Client instance
	 * @return \Aws\S3\S3Client
	 */
	public function getClient()
	{
		if ($this->_client === null) {
			$settings=[
				'version' => $this->version,
				'region' => $this->region,
				'credentials' => ['key' => $this->key, 'secret' => $this->secret],
			];
			if($this->enableV4) {
				$settings['signature']='v4';
			}				
			
			$this->_client = new S3Client($settings);
		}
		return $this->_client;
	}
}
