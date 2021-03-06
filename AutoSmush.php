<?php

require_once /*NoCompile*/__DIR__ . '/ImageOptimizer.php';

/**
 * AutoSmush
 * Optimize images
 *
 * @version 1.1.0
 * @author Roland Toth (tpr)
 * @author Matjaz Potocnik (matjazp)
 * @link https://github.com/matjazpotocnik/AutoSmush
 *
 * ProcessWire 2.x/3.x, Copyright 2011 by Ryan Cramer
 * Licensed under GNU/GPL v2, see LICENSE
 * https://processwire.com
 *
 */
class AutoSmush extends FieldtypeImage implements Module, ConfigurableModule {

	/**
	 * Module info
	 *
	 * @return array
	 *
	 */
	public static function getModuleInfo() {
		return array(
			'title'    => 'Auto Smush',
			'class'    => 'AutoSmush',
			'author'   => 'Roland Toth, Matja&#382; Poto&#269;nik',
			'version'  => '1.1.0',
			'summary'  => 'Optimize/compress images automatically on upload, resize and crop, ' .
									  'manually by clicking the button or link for each image or variation, ' .
									  'and in bulk mode for all images sitewide.',
			'href'     => 'https://processwire.com/talk/topic/14818-auto-smush/',
			'icon'     => 'leaf',
			'singular' => true,
			'autoload' => true
		);
	}

	/**
	 * Module configuraton values
	 *
	 */
	const WEBSERVICE = 'http://api.resmush.it/ws.php?exif=true&img=';
	const API_SIZELIMIT = 5242880; // 5 MB limit
	const API_ALLOWED_EXTENSIONS = 'png, jpg, jpeg, gif';
	const JPG_QUALITY_DEFAULT = '90';
	const CONNECTION_TIMEOUT = 30; // for large images and slow connection 30 sec might not be enough
	const JPG_QUALITY_THRESHOLD = 5; // no optimization if gain is less than 5%, only for jpegoptim, should prevent reoptmizing

	/**
	 * Array of messages reported by this module
	 * @var array
	 */
	static $messages = array();

	/**
	 * Array of settings for image-optimizer
	 * @var array
	 */
	protected $optimizeSettings = array(); //private?

	/**
	 * Array of all optimizers
	 * @var array
	 */
	protected $optimizers = array(); //private?

	/**
	 * Array of additional paths to look for optimizers executable
	 * @var array
	 */
	protected $optimizersExtraPaths = array(); //private?

	/**
	 * Array of allowed extensions for images
	 * @var array
	 */
	protected $allowedExtensions = array(); //private?

	/**
	 * Array of error codes returned by reSmush.it web service
	 * @var array
	 */
	protected $apiErrorCodes = array(); //private?

	/**
	 * Indicator if image needs to be optimized
	 * @var boolean
	 */
	protected $isOptimizeNeeded = false; //private?

	/**
	 * Indicator if image was optimized on upload
	 * @var boolean
	 */
	protected $isOptimizedOnUpload = false; //private?

	/**
	 * This module config data
	 * @var array
	 */
	protected $configData = array();

	/**
	 * PW FileLog object
	 * @var array
	 */
	protected $log; //private?


	/**
	 * Construct and set default configuration
	 *
	 */
	public function __construct() {

		self::$messages = array(
			'start'            => $this->_('Starting...'),
			'complete'         => $this->_('All done'),
			'error'            => $this->_('ERROR:'),
			'save_first'       => $this->_('Module settings have been modified, please save first'),
			'confirm'          => $this->_('Are you sure to continue?'),
			'canceled'         => $this->_('Canceled'),
			'canceling'        => $this->_('Canceling...'),
			'filelist'         => $this->_('Generating list of images'),
			'filelistnum'      => $this->_('Number of images: ')
		);

		$this->optimizers = array(
			'jpegtran'  => '',
			'jpegoptim' => '',
			'pngquant'  => '',
			'optipng'   => '',
			'pngcrush'  => '',
			'pngout'    => '',
			'advpng'    => '',
			'gifsicle'  => ''
		);

		// currently only jpegoptim is used for jpegs, I modified OptimizerFactory.php
		// pngs are chained in this order: pngquant, optipng, pngcrush, advpng
		$this->optimizeSettings = array(
			'ignore_errors'     => false, // in production could be set to true
			'execute_first'     => true, // true: execute just first optimizer in chain, false: execute all optimizers in chain
			'jpegtran_options'  => array('-optimize', '-progressive', '-copy', 'all'),
			'jpegoptim_options' => array('--preserve', '--all-progressive', '--strip-none', '-T' . self::JPG_QUALITY_THRESHOLD),
			'optipng_options'   => array('-i0', '-o2', '-quiet', '-preserve'),
			'advpng_options'    => array('-z', '-3', '-q'),
		);

		$this->allowedExtensions = array_map('trim', explode(',', self::API_ALLOWED_EXTENSIONS));

		// http://resmush.it/api
		$this->apiErrorCodes = array(
			'400' => $this->_('no url of image provided'),
			'401' => $this->_('impossible to fetch the image from URL (usually a local URL)'),
			'402' => $this->_('impossible to fetch the image from $_FILES (usually a local URL)'),
			'403' => $this->_('forbidden file format provided. Works strictly with jpg, png, gif, tif and bmp files.'),
			'404' => $this->_('request timeout from reSmush.it'),
			'501' => $this->_('internal error, cannot create a local copy'),
			'502' => $this->_('image provided too large (must be below 5MB)'),
			'503' => $this->_('internal error, could not reach remote reSmush.it servers for image optimization'),
			'504' => $this->_('internal error, could not fetch image from remote reSmush.it servers')
		);
	}


	/**
	 * Initialize log file
	 *
	 */
	public function init() {
		$cls = strtolower(__CLASS__);

		// pruneBytes returns error in PW prior to 3.0.13 if file does not exist
		if(!file_exists($this->wire('log')->getFilename($cls))) {
			$this->wire('log')->save($cls, 'log file created', array('showUser' => false, 'showURL' => false));
		}

		$this->log = new FileLog($this->wire('log')->getFilename($cls));
		method_exists($this->log, __CLASS__) ? $this->log->pruneBytes(20000) : $this->log->prune(20000);

		$paths = $this->wire('config')->paths;
		$this->optimizersExtraPaths = array(
			realpath($paths->siteModules . __CLASS__ . '/windows_binaries'),
			realpath($paths->root),
			realpath($paths->templates),
			realpath($paths->assets)
		);
	}

	/**
	 * Hook after ImageSizer::resize in auto mode
	 * Just set the flag that image is resized and it will be optimized if needed
	 *
	 */
	public function checkOptimizeNeeded() {
		$this->isOptimizeNeeded = true;
	}

	/**
	 * Hook after Pageimage::size in auto mode
	 * Optimize image on resize/crop
	 *
	 * @param HookEvent $event
	 *
	 */
	public function optimizeOnResize($event) {
		$thumb = $event->return;

		$this->optimize($thumb, false, 'auto');

		$event->return = $thumb;
	}

	/**
	 * Hook after ProcessCroppableImage3::executeSave in auto mode
	 * Optimize image on crop when FieldtypeCroppableImage3 is installed
	 *
	 * @param HookEvent $event
	 *
	 */
	public function optimizeOnResizeCI3($event) {

		// get page-id from post, sanitize, validate page and edit permission
		$id = intval($this->input->post->pages_id);
		$page = wire('pages')->get($id);
		if(!$page->id) throw new WireException('Invalid page');
		$editable = $page instanceof RepeaterPage ? $page->getForPage()->editable() : $page->editable();
		if(!$editable) throw new WirePermissionException('Not Editable');

		// get fieldname from post, sanitize and validate
		$field = wire('sanitizer')->fieldName($this->input->post->field);

		// UGLY WORKAROUND HERE TO GET A FIELDNAME WITH UPPERCASE LETTERS
		foreach($page->fields as $f) {
			if(mb_strtolower($f->name) != $field) continue;
			$fieldName = $f->name;
			break;
		}

		$fieldValue = $page->get($fieldName);
		if(!$fieldValue || !$fieldValue instanceof Pagefiles) throw new WireException('Invalid field');
		$field = $fieldValue;
		unset($fieldValue);

		// get filename from post, sanitize and validate
		$filename = wire('sanitizer')->name($this->input->post->filename);

		// $img is not variation
		$img = $field->get('name=' . $filename);
		if(!$img) throw new WireException('Invalid filename');

		// get suffix from post, sanitize and validate
		$suffix = wire('sanitizer')->name($this->input->post->suffix);
		if(!$suffix || strlen($suffix) == 0) throw new WireException('No suffix');

		// build the file
		$file = basename($img->basename, '.' . $img->ext) . '.-' . strtolower($suffix) . '.' . $img->ext;

		// get the variation
		$myimage = $img->getVariations()->get($file);

		if(!$myimage) throw new WireException('Invalid filename');

		$this->optimize($myimage, false, 'auto');

	}

	/**
	 * Hook before InputfieldFile::fileAdded in auto mode
	 * Optimize image on upload
	 *
	 * @param HookEvent $event
	 * @return bool false if image extension is not in allowedExtensions
	 *
	 */
	public function optimizeOnUpload($event) {
		$img = $event->argumentsByName('pagefile');

		// ensure only images are optimized
		if(!$img instanceof Pageimage) return;

		// ensure only images with allowed extensions are optimized
		if(!in_array($img->ext, $this->allowedExtensions)) return;

		// make a backup
		if(isset($this->configData['optAutoAction']) && in_array('backup', $this->configData['optAutoAction'])) {
			@copy($img->filename, $img->filename . '.autosmush');
		}

		// optimize
		if($this->optimize($img, true, 'auto') !== false) $this->isOptimizedOnUpload = true;
	}

	/**
	 * Hook after InputfieldImage::renderItem in manual mode
	 * Add optimize link/button to the image markup
	 *
	 * @param HookEvent $event
	 *
	 */
	public function addOptButton($event) {
		// $event->object = InputfieldFile
		// $event->object->value = Pagefiles
		// $event->arguments[0] or $event->argumentsByName('pagefile') = Pagefile

		$file = $event->argumentsByName('pagefile');

		if(!in_array($file->ext, $this->allowedExtensions)) return; // not an image file

		$id = $file->page->id;
		$url = $this->wire('config')->urls->admin . 'module/edit?name=' . __CLASS__ .
					 "&mode=optimize&id=$id&file=$id,{$file->basename}";
		$title =  $this->_('Optimize image');
		$text = $this->_('Optimize');
		$optimizing = $this->_('Optimizing');
		if($this->isOptimizedOnUpload) $text = $this->_('Optimized on upload');

		if(stripos($event->return, 'InputfieldFileName') !== false) {
			// InputfieldFileName class found, used in PW versions up to 3.0.17
			$link = "<a href='$url' data-optimizing='$optimizing' class='InputfieldImageOptimize' title='$title'>$text</a>";
			if(stripos($event->return, '</p>') !== false) { // insert link right before </p>
				$event->return = str_replace('</p>', $link . '</p>', $event->return);
			}
		} else if(stripos($event->return, 'InputfieldImageButtonCrop') !== false) {
			// new version with button
			// there is also InputfieldImage::renderButtons hook
			$link = "<a href='$url&var=1' title='$title'>$text</a>";
			//if($this->wire('user')->admin_theme == 'AdminThemeUikit') {
			//	$b  = "<button type='button' data-href='$url' data-optimizing='$optimizing' class='InputfieldImageOptimize1 uk-button uk-button-text uk-margin-right'>";
			//} else {
				$b  = "<button type='button' data-href='$url' data-optimizing='$optimizing' class='InputfieldImageOptimize1 ui-button ui-corner-all ui-state-default'>";
			//}
			$b .= "<span class='ui-button-text'><span class='fa fa-leaf'></span><span> $text</span></span></button>";
			if(stripos($event->return, '</small>') !== false) { // insert button right before </small> as the last (third) button, after Crop and Variations buttons
				$event->return = str_replace('</small>', $b . '</small>', $event->return);
			}
		} else {
			$this->log->save('addOptButton: class InputfieldFileName/InputfieldImageButtonCrop not found');
		}

	}

	/**
	 * Hook after ProcessPageEditImageSelect::executeVariations in manual mode
	 * Add optimize button to the variations page
	 *
	 * @param HookEvent $event
	 *
	 */
	public function addOptButtonVariations($event) {

		$opturl = $this->wire('config')->urls->admin . 'module/edit?name=' . __CLASS__ . '&mode=optimize&var=1';
		$b = $this->wire('modules')->get('InputfieldButton');
		$b->attr('id', 'optimizeVariants');
		$b->attr('data-href', $opturl);
		$b->attr('value', $this->_('Optimize Checked'));
		$b->attr('data-optimizing', $this->_('Optimizing'));
		$b->attr('data-check', $this->_('No variation checked!'));
		$b->icon = 'leaf';
		$b->addClass('InputfieldOptimizeVariants');
		$b->attr('style', 'display:none');

		$needle = "<ul class='Inputfields'>";
		//if($this->wire('user')->admin_theme == 'AdminThemeUikit') $needle = "<ul class='Inputfields uk-grid-collapse' uk-grid>";
		if($this->wire('user')->admin_theme == 'AdminThemeUikit') $needle = "<ul class='Inputfields uk-grid-collapse uk-grid-match' uk-grid uk-height-match='target: > .Inputfield:not(.InputfieldStateCollapsed) > .InputfieldContent'>";
		if(stripos($event->return, $needle)) {
			$event->return = str_replace($needle, $needle . $b->render(), $event->return);
		}
	}

	/**
	 * Process image optimize via ajax request when optimize link/button is clicked
	 * or in bulk mode
	 *
	 * @param bool $getVariations true when optimizing variation, false if original
	 * @return string|json
	 *
	 */
	public function onclickOptimize($getVariations = false) {

		$err = "<i style='color:red' class='fa fa-times-circle'></i>";
		$input = $this->wire('input');
		$status = array(
			'error'           => null, // various errors
			'error_api'       => null, // erros from reSmush.it
			'percentNew'      => '0', // reduction percentage
			'file'            => '', // image name
			'basedir'         => '', // page id where image is eg. 1234
			'url'             => '#' // full url to the image
		);

		$file = $input->get('file'); // 1234,image.jpg
		$id = (int) $input->get('id'); // could also get id from file var
		$bulk = $input->get('bulk');
		$m = ($bulk == 1) ? 'bulkOptimize: ' : 'onclickOptimize: ';
		// $file = $this->wire('sanitizer')->pageNameUTF8($input->get('file'));

		if($this->wire('config')->demo) {
			$msg = $this->_('Optimization disabled in demo mode!');
			$this->log->save($m . $msg);
			if($bulk == 1) {
				$status['error'] = $msg;
				header('Content-Type: application/json');
				echo json_encode($status);
			} else {
				echo $getVariations ? $err : $msg;
			}
			exit(0);
		}

		$page = $this->wire('pages')->get($id);

		if(!$id || !$file || !$page->id) {
			$msg = 'Invalid data!';
			$this->log->save($m . $msg);
			if($bulk == 1) {
				$status['error'] = 'invalid data';
				header('Content-Type: application/json');
				echo json_encode($status);
			} else {
				echo $getVariations ? $err : $msg;
			}
			exit(0);
		}


		$status['file'] = $this->wire('config')->urls->files . $id . '/' . explode(',', $file)[1]; // fake image name
		$status['basedir'] = $id; // page id where image is eg. 1234

		// this doesn't work with CroppableImage3
		//$img = wire('modules')->get('ProcessPageEditImageSelect')->getPageImage($getVariations);

		// old version
		/*$myimage = null;
		$page = $this->wire('pages')->get($id);
		$file = explode(',', $file)[1];
		$imgs = $this->wire('modules')->get('ProcessPageEditImageSelect')->getImages($page);

		foreach($imgs as $img) {
			if($img->basename == $file) {
				// original found
				$myimage = $img;
				break;
			}
			$myimage = $img->getVariations()->get($file);
			if($myimage) {
				// variation found
				break;
			}
		}*/

		// new version
		$myimage = $this->getPageImage($page, true);

		if(!$myimage) {
			$file = explode(',', $file)[1];
			$msg = ' not found!';
			$this->log->save($m . $file . $msg);
			if($bulk == 1) {
				$status['error'] = 'image not found';
				header('Content-Type: application/json');
				echo json_encode($status);
			} else {
				echo $getVariations ? $err : $msg;
			}
			exit(0);
		}

		$img = $myimage;

		$src_size = (int) @filesize($img->filename);
		if($src_size == 0) {
			// this shouldn't happen but who knows
			if($bulk == 1) {
				$status['error'] = 'zero file size';
				header('Content-Type: application/json');
				echo json_encode($status);
			} else {
				echo 'Zero file size!';
			}
			exit(0);
		}

		if($bulk == 1) {
			$status = $this->optimize($img, true, 'bulk');
			header('Content-Type: application/json');
			echo json_encode($status);
			exit(0);
		} else {
			$status = $this->optimize($img, true, 'manual');
			if($status['error'] !== null) {
				$msg = $this->_('Not optimized, check log!');
				// errors are already logged by optimize method
				echo $getVariations ? $err : $msg;
				exit(0);
			}
		}

		@clearstatcache(true, $img->filename);
		$dest_size = @filesize($img->filename);

		if($getVariations) {
			echo wireBytesStr($dest_size);
		} else {
			//$percentNew = 100 - (int) ($dest_size / $src_size * 100);
			//printf($this->x_x('Optimized, reduced by %1$d%%'), $percentNew);
			echo $this->_('Optimized, new size:') . ' ' . wireBytesStr($dest_size);
		}

		exit(0);
	}

	/**
	 * Hook after InputfieldFile::processInputDeleteFile deletes original uploaded file
	 *
	 * @param HookEvent $event
	 *
	 */
	public function deleteBackup($event) {
		$img = $event->argumentsByName('pagefile');

		@unlink($img->filename . '.autosmush');
	}

	/**
	 * Create a list of images to be optimized and echo them in JSON format.
	 * Called from this module settings in bulk mode, on button click
	 *
	 */
	public function bulkOptimize() {

		// check if engine is selected
		if(!isset($this->configData['optBulkEngine'])) {
			$this->log->save('No engine selected (bulk).');
			$status = array(
				'error' => 'No engine selected.',
				'numImages' => 0
			);
			header('Content-Type: application/json');
			echo json_encode($status);
			exit(0);
		}

		$processOriginals  = isset($this->configData['optBulkAction']) && in_array('optimize_originals', $this->configData['optBulkAction']);
		$processVariations = isset($this->configData['optBulkAction']) && in_array('optimize_variations', $this->configData['optBulkAction']);

		// get all fields of type FieldtypeImage or FieldtypeCroppableImage3
		$selector = 'type=FieldtypeImage';
		if(wire('modules')->isInstalled('FieldtypeCroppableImage3')) $selector .= '|FieldtypeCroppableImage3';
		$imageFields = wire('fields')->find($selector);

		// get total number of pages with images
		$numPagesWithImages = 0;
		foreach ($imageFields as $f) $numPagesWithImages += wire('pages')->count("$f>0, include=all");

		$allImages = array();
		$limit = 1;
		$start = abs((int) wire('input')->get('start'));
		if($start >= $numPagesWithImages) $start = $numPagesWithImages;

		// get all images from pages that have image fields
		foreach ($imageFields as $f) {
			foreach (wire('pages')->find("$f>0, include=all, start=$start, limit=$limit") as $p) {
				$images = $p->getUnformatted($f->name);
				$id = $p->id;
				$filesArray = false;

				foreach ($images as $i) {
					if($processOriginals) $allImages[] = "$id,{$i->basename}";

					if($processVariations) {

						// create array of files in pagefiles folder eg. /site/assest/files/1234/
						if($filesArray === false) {
							$filesArray = array_diff(@scandir($i->pagefiles->path), array('.', '..', $i->basename)); // array_diff removes ., .. and self
						}

						// iterate over array of files and check if file is variation of current image
						foreach($filesArray as $file) {
							if($this->isVariation($i->basename, $file)) $allImages[] = "$id,$file";
						}
					}
				}
			}
		}

		$totalImages = count($allImages);
		$a = array();
		if($start < $numPagesWithImages) {
			$a["counter"] =	sprintf($this->_('Processing page %1$d out of %2$d - {%3$d}%% complete'), // {} is placeholder, must be present
											$start+1, $numPagesWithImages, (int) ($start / $numPagesWithImages * 100));
		} else {
			$a["counter"] =	sprintf($this->_('All done - {100}%% complete'));
		}
		$a["numBatches"] = $numPagesWithImages;
		$a["numImages"] = $totalImages;
		$a["images"] = $allImages;
		header('Content-Type: application/json');
		echo json_encode($a);
		exit(0);

	}


	/**
	 * Optimize image
	 *
	 * @param Pageimage $img Pageimage object
	 * @param boolean $force true, when you want to force optimize the image
	 * @param string $mode 'auto', 'manual' or 'bulk'
	 * @return array
	 *
	 */
	public function optimize($img, $force = false, $mode = 'auto') {
		// todo: test with $config->pagefileExtendedPaths = true

		//$demo = false;
		$demo = $this->wire('config')->demo;

		$status = array(
			'error'           => null, // various errors
			'error_api'       => null, // errors from reSmush.it
			'percentNew'      => '0', // reduction percentage
			'file'            => $img->basename, // image name
			'basedir'         => basename(dirname($img->filename)), // page id where image is eg. 1234
			'url'             => $img->httpUrl // full url to the image
		);

		// force is only used in optimizeOnUpload
		if(!$force && !$this->isOptimizeNeeded) return false; // todo: return array?

		if(!in_array($img->ext, $this->allowedExtensions)) {
			$error = '($mode): Error optimizing ' . $img->filename . ': unsupported extension';
			$this->log->save($error);
			$status['error'] = 'unsupported extension';
			return $status;
		}

		$percentNew = 0;
		$opt = $src_size = $dest_size = $q = '';
		$mode1 = ucfirst(strtolower($mode));
		if(isset($this->configData["opt{$mode1}Quality"])) $q = $this->configData["opt{$mode1}Quality"];

		array_push($this->optimizeSettings['jpegoptim_options'], '-m' . $q);
		$this->optimizeSettings['jpegoptim_options'] = array_unique($this->optimizeSettings['jpegoptim_options']);

		if(isset($this->configData["opt{$mode1}Engine"]) && $this->configData["opt{$mode1}Engine"] == 'resmushit') {
			// use resmush.it web service
			$opt = "reSmush.it ($mode): ";

			if($img->filesize >= self::API_SIZELIMIT) {
				$error = 'Error optimizing ' . $img->filename . ', file larger then ' . self::API_SIZELIMIT . ' bytes';
				$this->log->save($opt . $error);
				$status['error'] = 'file to large';
				return $status;
			}

			// upload image using curl
			/*
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, self::WEBSERVICE . '&qlty=' . $q);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
			curl_setopt($ch, CURLOPT_TIMEOUT, self::CONNECTION_TIMEOUT);
			curl_setopt($ch, CURLOPT_POST, true);
			if(version_compare(PHP_VERSION, '5.5') >= 0) {
					$postfields = array ('files' => new CURLFile($img->filename, 'image/' . $img->ext, $img->basename));
					curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);
			} else {
					$postfields = array ('files' => '@'.$img->filename);
			}
			curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
			$data = curl_exec($ch);
			if($data === false || curl_errno($ch)) {
				$error = 'Error optimizing ' . $img->filename . ': cURL error: ' . curl_error($ch);
				$this->log->save($error);
				$status['error'] = curl_error($ch);
				return $status;
			}
			curl_close($ch);
			*/

			// upload image using WireHttp class
			$http = new WireHttp();
			$http->setTimeout(self::CONNECTION_TIMEOUT); // important!!! default is 4.5 sec and that is to low
			$eol = "\r\n";
			$content = '';
			$boundary = strtolower(md5(time()));
			$content .= '--' . $boundary . $eol;
			$content .= 'Content-Disposition: form-data; name="files"; filename="' . $img->basename . '"' . $eol;
			$content .= 'Content-Type: image/' . $img->ext . $eol . $eol; // two eol's!!!!!
			$content .= file_get_contents($img->filename) . $eol;
			$content .= '--' . $boundary . '--' . $eol;
			$http->setHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary);
			$data = $http->post(self::WEBSERVICE . '&qlty=' . $q, $content);
			if(is_bool($data)) {
				$error = 'Error optimizing ' . $img->filename . ', ';
				$error1 = $data === true ? 'request timeout' : $http->getHttpCode(true);
				$this->log->save($opt . $error . $error1 . ' (possible request timeout)');
				$status['error'] = $error1;
				return $status;
			}

			$response = json_decode($data);

			if($response === null) {
				$error = 'Error optimizing ' . $img->filename . ', returned data is empty';
				$this->log->save($opt . $error);
				$status['error'] = 'returned data is empty';
				return $status;
			}

			if(isset($response->error)) {
				$error = isset($this->apiErrorCodes[$response->error]) ? $this->apiErrorCodes[$response->error] : $response->error;
				$this->log->save($opt . 'Error optimizing ' . $img->filename . ', ' . $error);
				$status['error'] = $error;
				$status['error_api'] = $error;
				return $status;
			}

			$dest_size = $response->dest_size;
			$src_size = $response->src_size;

			// write to file only if optimized image is smaller
			if($dest_size < (int) ((100 - self::JPG_QUALITY_THRESHOLD) / 100 * $src_size)) {

				$http = new WireHttp();
				$http->setTimeout(self::CONNECTION_TIMEOUT);
				try {
					if(!$demo) $http->download($response->dest, $img->filename);//, array('useMethod' => 'fopen'));
					//$percentNew = 100 - (int) ($response->dest_size / $response->src_size * 100);
					$percentNew = (int) $response->percent;
				} catch(Exception $e) {
					$error = 'Error retreiving ' . $response->dest . ', ' . $e->getMessage();
					$this->log->save($opt . $error);
					$status['error'] = $e->getMessage();
					return $status;
				}
			}
		}

		else if(isset($this->configData["opt{$mode1}Engine"]) && $this->configData["opt{$mode1}Engine"] == 'localtools') {
			// use local (server) tools
			$opt = "ServerTools ($mode): ";

			$src_size = filesize($img->filename);
			$factory = new \ImageOptimizer\OptimizerFactory($this->optimizeSettings);
			$optimizer = $factory->get();
			//$optimizer = $factory->get('jpegoptim');

			try {
				// optimizer will throw exceptions if none of the optimizers in chain is not found
				if(!$demo) $optimizer->optimize($img->filename);  // optimized file overwrites original!
			} catch (Exception $e) {
				$error = $e->getMessage();
				$this->log->save($opt . 'Error optimizing ' . $img->filename . ', ' . $error);
				$status['error'] = $error;
				return $status;
			}

			clearstatcache(true, $img->filename);
			$dest_size = filesize($img->filename);
			$percentNew = 100 - (int) ($dest_size / $src_size * 100);

		} else {
			// no engine selected
			$opt = "No engine selected ($mode). ";
			$src_size = filesize($img->filename);
			$this->log->save($opt . $img->filename . ', source ' . $src_size . ' bytes');
			return $opt; // todo: return false, array, json?
		}

		// image is optimized
		$this->log->save($opt . $img->filename . ', source ' . $src_size . ' bytes, destination ' . $dest_size . ' bytes, reduction ' . $percentNew . '%');
		$status['percentNew'] = $percentNew . "";
		return $status;

	}

	/**
	 * Module fields
	 *
	 * @param array $data config data
	 * @return InputfieldWrapper
	 *
	 */
	public function getModuleConfigInputfields(array $data) {

		$fields = new InputfieldWrapper();
		$modules = $this->wire('modules');
		//$data = array_merge(self::$defaultConfig, $data);
		if($this->wire('config')->demo) $this->error($this->_('Optimization disabled in demo mode!'));

		// automatic mode
		$fieldset              = $modules->get('InputfieldFieldset');
		$fieldset->label       = $this->_('Automatic Mode');
		$fieldset->description = $this->_('Automatically optimize images on upload (originals) or on resize/crop (variations).');
		$fields->add($fieldset);

		$field                = $modules->get('InputfieldRadios');
		$field->name          = 'optAutoEngine';
		$field->label         = $this->_('Engine');
		$field->columnWidth   = 40;
		$field->addOption('resmushit',  $this->_('Use reShmush.it online service'));
		$field->addOption('localtools', $this->_('Use optimization tools available on web server'));
		$field->value         = isset($data['optAutoEngine']) ? $data['optAutoEngine'] : 'resmushit';
		$fieldset->add($field);

		$field                = $modules->get('InputfieldCheckboxes');
		$field->name          = 'optAutoAction';
		$field->label         = $this->_('Action');
		$field->columnWidth   = 40;
		$field->addOption('optimize_originals', $this->_('Optimize on upload'));
		$field->addOption('backup', $this->_('Backup original'));
		$field->addOption('optimize_variations', $this->_('Optimize on resize/crop'));
		if($modules->isInstalled('FieldtypeCroppableImage3')) $field->addOption('optimize_variationsCI3', $this->_('Optimize on resize/crop for CI3'));
		$field->value         = isset($data['optAutoAction']) ? $data['optAutoAction'] : array();
		$fieldset->add($field);

		$field                = $modules->get('InputfieldInteger');
		$field->name          = 'optAutoQuality';
		$field->label         = $this->_('JPG quality');
		$field->columnWidth   = 20;
		$field->attr('min', '1');
		$field->attr('max', '100');
		$field->value         = isset($data['optAutoQuality']) ? $data['optAutoQuality'] : self::JPG_QUALITY_DEFAULT;
		$fieldset->add($field);


		// manual mode
		$fieldset = $modules->get('InputfieldFieldset');
		$fieldset->label = $this->_('Manual Mode');
		$fieldset->description = $this->_('Add Optimize button/link to page and/or variations modal.');
		$fields->add($fieldset);

		$field                = $modules->get('InputfieldRadios');
		$field->name          = 'optManualEngine';
		$field->label         = $this->_('Engine');
		$field->columnWidth   = 40;
		$field->addOption('resmushit',  $this->_('Use reShmush.it online service'));
		$field->addOption('localtools', $this->_('Use optimization tools available on web server'));
		$field->value         = isset($data['optManualEngine']) ? $data['optManualEngine'] : 'resmushit';
		$fieldset->add($field);

		$field                = $modules->get('InputfieldCheckboxes');
		$field->name          = 'optManualAction';
		$field->label         = $this->_('Action');
		$field->columnWidth   = 40;
		$field->addOption('optimize_originals',  $this->_('Add optimize button/link to page edit'));
		$field->addOption('optimize_variations', $this->_('Add optimize button to variations modal'));
		$field->value         = isset($data['optManualAction']) ? $data['optManualAction'] : array('optimize_originals', 'optimize_variations');
		$fieldset->add($field);

		$field                = $modules->get('InputfieldInteger');
		$field->name          = 'optManualQuality';
		$field->label         = $this->_('JPG quality');
		$field->columnWidth   = 20;
		$field->attr('min', '1');
		$field->attr('max', '100');
		$field->value         = isset($data['optManualQuality']) ? $data['optManualQuality'] : self::JPG_QUALITY_DEFAULT;
		$fieldset->add($field);


		// bulk mode
		$fieldset              = $modules->get('InputfieldFieldset');
		$fieldset->name        = 'bulkoptimize_fieldset';
		$fieldset->label       = $this->_('Bulk Mode');
		$fieldset->description = $this->_('Optimize ALL images on button click.');
		$fields->add($fieldset);

		$field                = $modules->get('InputfieldRadios');
		$field->attr('id+name', 'optBulkEngine');
		$field->label         = $this->_('Engine');
		$field->columnWidth   = 40;
		$field->addOption('resmushit',  $this->_('Use reShmush.it online service'));
		$field->addOption('localtools', $this->_('Use optimization tools available on web server'));
		$field->value         = isset($data['optBulkEngine']) ? $data['optBulkEngine'] : 'resmushit';
		$fieldset->add($field);

		$field                = $modules->get('InputfieldCheckboxes');
		$field->name          = 'optBulkAction';
		$field->id            = 'optBulkAction';
		$field->label         = $this->_('Action');
		$field->columnWidth   = 40;
		$field->addOption('optimize_originals',  $this->_('Optimize originals'));
		$field->addOption('optimize_variations', $this->_('Optimize variations'));
		$field->value         = isset($data['optBulkAction']) ? $data['optBulkAction'] : '';
		$fieldset->add($field);

		$field                = $modules->get('InputfieldInteger');
		$field->name          = 'optBulkQuality';
		$field->id            = 'optBulkQuality';
		$field->label         = $this->_('JPG quality');
		$field->columnWidth   = 20;
		$field->attr('min', '1');
		$field->attr('max', '100');
		$field->value         = isset($data['optBulkQuality']) ? $data['optBulkQuality'] : self::JPG_QUALITY_DEFAULT;
		$fieldset->add($field);

		$field                = $modules->get('InputfieldMarkup');
		$field->id            = 'bulkoptimize';
		$field->label         = $this->_('Bulk optimize');
		$field->icon          = 'coffee';
		$field->description   = $this->_('Click the button below to optimize all images sitewide.');
		//$field->value         = '<p class="description" style="margin-bottom:0;margin-top:-1em"><strong>' .
		$field->value         = '<p class="description"><strong>' .
														$this->_('WARNING: Using web server optimization tools is CPU intensive process. ') .
														$this->_('Running bulk optimize on large amount of images may take a while to finish.') .
														'</strong></p>';
		if($this->wire('config')->demo) {
			$field->value       .= '<span class="NoticeError">&nbsp;' . $this->_('Optimization disabled in demo mode!') . '&nbsp;</span>';
		}
		//} else {
			$field_button         = $modules->get('InputfieldButton');
			$field_button->attr('id+name', 'optimize_all');
			$field_button->attr('data-url', 'edit?name=' . __CLASS__ . '&mode=bulk');
			$field_button->attr('data-optimizeurl', 'edit?name=' . wire('input')->get('name') . '&mode=optimize&bulk=1');
			$field_button->attr('data-start-msg', $this->getMessage('start'));
			$field_button->attr('data-complete-msg', $this->getMessage('complete'));
			$field_button->attr('data-error-msg', $this->getMessage('error'));
			$field_button->attr('data-confirm-msg', $this->getMessage('confirm'));
			$field_button->attr('data-save-first-msg', $this->getMessage('save_first'));
			$field_button->attr('data-filelist-msg', $this->getMessage('filelist'));
			$field_button->attr('data-filelistnum-msg', $this->getMessage('filelistnum'));
			$field_button->value  = $this->_('Start bulk image optimize');
			$field->add($field_button);

			$field_button         = $modules->get('InputfieldButton');
			$field_button->attr('id+name', 'cancel_all');
			$field_button->attr('data-canceled-msg', $this->getMessage('canceled'));
			$field_button->attr('data-canceling-msg', $this->getMessage('canceling'));
			$field_button->value  = $this->_('Cancel');
			$field->add($field_button);
		//}

			$fieldm               = $modules->get('InputfieldMarkup');
			$fieldm->attr('id', 'progbarwrapper');
			$fieldm->value        = '<progress max="100" value="0" id="progressbar"></progress><span id="percent"></span><p id="result"></p>' .
															'<progress max="100" value="0" id="progressbar1"></progress><span id="percent1"></span>';
			$field->add($fieldm);

		$fieldset->add($field);

		// local tools info
		$fieldset = $modules->get('InputfieldFieldset');
		$fieldset->name = 'localoptimizers_fieldset';
		$fieldset->label = $this->_('Advanced options for web server optimization tools');
		$fieldset->collapsed = Inputfield::collapsedYes;
		$fields->add($fieldset);

		$table = $modules->get('MarkupAdminDataTable');
		$table->setEncodeEntities(false);
		$table->headerRow(array(
			$this->_('Optimizer'),
			$this->_('Path')
		));
		foreach($this->optimizers as $optimizer => $path) {
			if($path === '') $path = $this->_('Not found');
			$table->row(array($optimizer, $path));
		}

		$field = $modules->get('InputfieldMarkup');
		//$field->skipLabel = true;
		$field->label = $this->_('Search path');
		$field->value = '<p>' . $this->findPaths() . '</p>' . $table->render();
		$fieldset->add($field);

		$field = $modules->get('InputfieldCheckbox');
		$field->name = 'optChain';
		$field->label = $this->_('Enable optimizers chaining');
		$field->description = $this->_('If unchecked, only the first available optimizer for specific image type will run (default). If checked, all available optimizers will run one after another.');
		$field->value = isset($data['optChain']) ? $data['optChain'] : '';
		$field->checked = ($field->value == 1) ? 'checked' : '';
		$fieldset->add($field);

		return $fields;
	}

	/**
	 * Get message text
	 *
	 * @param string $key
	 * @return string
	 *
	 */
	private function getMessage($key = '') {
		return isset(self::$messages[$key]) ? self::$messages[$key] : '';
	}


	/**
	 * Checks for existance of optimizer executables
	 *
	 */
	private function checkOptimizers() {
		foreach($this->optimizers as $optimizer => $path) {
			$finder = new Symfony\Component\Process\ExecutableFinder();
			$exec = $finder->find($optimizer, '', $this->optimizersExtraPaths);
			$this->optimizers[$optimizer] = $exec;
		}
	}

	/**
	 * Find paths to serach for optimizer executables
	 *
	 * @return string path
	 *
	 */
	private function findPaths() {
		if(ini_get('open_basedir')) {
			$searchPath = explode(PATH_SEPARATOR, ini_get('open_basedir'));
			$dirs = array();
			foreach ($searchPath as $path) {
				// Silencing against https://bugs.php.net/69240
				if(@is_dir($path)) $dirs[] = $path;
			}
		} else {
			$dirs = array_merge(
				explode(PATH_SEPARATOR, getenv('PATH') ?: getenv('Path')),
				$this->optimizersExtraPaths
			);
			return implode(PATH_SEPARATOR . ' ', array_filter($dirs));
		}
	}


	/**
	 * Given two filenames, check if $variationName is variation of $originalName
	 * This is very simplified version.
	 * isVariation('123.jpg', '123.0x260.jpg'); //true
	 * isVariation('123.jpg', '123.-portrait.jpg'); //true
	 * isVariation('123.jpg', '123.jpg'); //false
	 * isVariation('123.jpg', '456.jpg'); //false
	 *
	 * @param string $originalName
	 * @param string $variationName
	 * @return bool
	 *
	 */
	public function isVariation($originalName, $variationName) {

		// if file is the same as the original, then it's not a variation
		if($originalName === $variationName) return false;

		// if file doesn't start with the original name then it's not a variation
		$test1 = substr($variationName, 0, strpos($variationName, '.'));
		$test2 = substr($originalName, 0, strpos($originalName, '.'));
		if($test1 !== $test2) return false;

		return true;
	}

	/**
	 * Return the Pageimage object from page
	 * This is modified version of the method in ProcessPageEditImageSelect.module to account for
	 * variations made by CroppableImage3
	 *
	 * @param Page $page page that contains images
	 * @param bool $getVariation Returns the variation specified in the URL. Otherwise returns original (default).
	 * @return Pageimage|null
	 *
	 */
	public function getPageimage(Page $page, $getVariation = false) {

		//$images = $this->getImages($this->page);
		$images = $this->getImages($page); //MP
		$file = basename($this->input->get->file);
		$variationFilename = '';

		if(strpos($file, ',') === false) {
			// prepend ID if it's not there, needed for ajax in-editor resize
			$originalFilename = $file;
			$file = $page->id . ',' . $file;
		} else {
			// already has a "123," at beginning
			list($unused, $originalFilename) = explode(',', $file);
		}

		$originalFilename = $this->wire('sanitizer')->filename($originalFilename, false, 1024);

		// if requested file does not match one of our allowed extensions, abort
		//if(!preg_match('/\.(' . $this->extensions . ')$/iD', $file, $matches)) throw new WireException("Unknown image file");
		$extensions = 'jpg|jpeg|gif|png|svg'; //MP
		//$extensions = self::API_ALLOWED_EXTENSIONS; //MP
		//if(!preg_match('/\.(' . $extensions . ')$/iD', $file, $matches)) return null;
		if(!preg_match('/\.(' . $extensions . ')$/iD', $file, $matches)) return null; //MP

		// get the original, non resized version, if present
		// format:            w x h    crop       -suffix
		//if(preg_match('/(\.(\d+)x(\d+)([a-z0-9]*)(-[-_.a-z0-9]+)?)\.' . $matches[1] . '$/', $file, $matches)) {
		if(preg_match('/(\.(\d?)x?(\d?)([a-z0-9]*)(-[-_.a-z0-9]+)?)\.' . $matches[1] . '$/', $file, $matches)) { //MP
			// filename referenced in $_GET['file'] IS a variation
			// Follows format: original.600x400-suffix1-suffix2.ext
			// Follows format: original.-suffix1-suffix2.ext for CroppableImage3 //MP
			$this->editWidth = (int) $matches[2];
			$this->editHeight = (int) $matches[3];
			$variationFilename = $originalFilename;
			$originalFilename = str_replace($matches[1], '', $originalFilename); // remove dimensions and optional suffix
		} else {
			// filename referenced in $_GET['file'] is NOT a variation
			$getVariation = false;
		}

		// update $file as sanitized version and with original filename only
		$file = "{$page->id},$originalFilename";

		// if requested file is not one that we have, abort
		//if(!array_key_exists($file, $images)) throw new WireException("Invalid image file: $file");
		if(!array_key_exists($file, $images)) return null; //MP

		// return original
		if(!$getVariation) return $images[$file];

		// get variation
		$original = $images[$file];
		$variationPathname = $original->pagefiles->path() . $variationFilename;
		$pageimage = null;
		if(is_file($variationPathname)) $pageimage = $this->wire(new Pageimage($original->pagefiles, $variationPathname));
		//if(!$pageimage) throw new WireException("Unrecognized variation file: $file");
		if(!$pageimage) return null; //MP

		return $pageimage;
	}

	/**
	 * Get all Pageimage objects on page
	 * This is modified version of the method in ProcessPageEditImageSelect.module
	 *
	 * @param Page $page
	 * @param array|WireArray $fields
	 * @param int $level Recursion level (internal use)
	 * @return Pageimage array
	 *
	 */
	public function getImages(Page $page, $fields = array(), $level = 0) {

		$allImages = array();
		if(!$page->id) return $allImages;

		if(empty($fields)) $fields = $page->fields;

		foreach($fields as $field) {

			if($field->type instanceof FieldtypeRepeater) {
			//if(wireInstanceOf($field->type, 'FieldtypeRepeater')) { //MP only available in PW 3.0.73
				// get images that are possibly in a repeater
				$repeaterValue = $page->get($field->name);
				if($repeaterValue instanceof Page) $repeaterValue = array($repeaterValue); //MP support for FieldtypeFieldsetPage
				if($repeaterValue) foreach($repeaterValue as $p) {
					$images = $this->getImages($p, $p->fields, $level+1);
					if(!count($images)) continue;
					$allImages = array_merge($allImages, $images);
				}
				continue;
			}

			if(!$field->type instanceof FieldtypeImage) continue;
			$images = $page->getUnformatted($field->name);
			if(!count($images)) continue;

			foreach($images as $image) {
				$key = $page->id . ',' . $image->basename;  // page_id,basename for repeater support
				$allImages[$key] = $image;
			}
		}

		return $allImages;
	}


	/**
	 * Main entry point
	 * Set hooks and handle ajax requests
	 *
	 */
	public function ready() {

		$page = $this->wire('page');
		if($page->template != 'admin') return;

		$this->configData = $this->wire('modules')->getModuleConfigData($this);

		$config = $this->wire('config');
		$input = $this->wire('input');
		$mode = $input->get('mode');

		$this->checkOptimizers();
		foreach($this->optimizers as $optimizer => $path) $this->optimizeSettings[$optimizer . '_bin'] = $path;
		if(isset($this->configData['optChain']) && $this->configData['optChain'] == 1) $this->optimizeSettings['execute_first'] = false;

		if($input->get('name') === __CLASS__) {

			// optimize images in bulk mode on button click
			if($mode === 'bulk') $this->bulkOptimize();

			// optimize images in manual mode on clicking optimize button/link or on image variations modal
			if($mode === 'optimize') $this->onclickOptimize(($input->get('var') == 1)); // &var=1 => process variation

			// add assets
			$this->wire('modules')->get('JqueryMagnific');
			$config->scripts->add($config->urls->siteModules . __CLASS__ . '/' . __CLASS__ . '.js');//?v=' . time());
			$config->styles->add($config->urls->siteModules . __CLASS__ . '/' . __CLASS__ . '.css');//?v=' . time());
		}

		// add optimize button/link in manual mode on page/image edit
		if(($page->process == 'ProcessPageEdit' || $page->process == 'ProcessPageEditImageSelect') && isset($this->configData['optManualEngine'])) {
			if(isset($this->configData['optManualAction']) && in_array('optimize_originals', $this->configData['optManualAction'])) {
				// add link/button
				$this->addHookAfter('InputfieldImage::renderItem', $this, 'addOptButton');
			}
			if(isset($this->configData['optManualAction']) && in_array('optimize_variations', $this->configData['optManualAction'])) {
				// add button on variations page
				// for new image field introduced after 3.0.17 we could hook after InputfieldImage::renderButtons
				$this->addHookAfter('ProcessPageEditImageSelect::executeVariations', $this, 'addOptButtonVariations');
			}
			$config->scripts->add($config->urls->siteModules . __CLASS__ . '/' . __CLASS__ . 'PageEdit.js');//?v=' . time());
		}

		// optimize images in auto mode on upload
		if(isset($this->configData['optAutoAction']) && in_array('optimize_originals', $this->configData['optAutoAction']) && !$config->demo) {
			$this->addHookBefore('InputfieldFile::fileAdded', $this, 'optimizeOnUpload');
			$config->js('AutoSmush', $this->_('Optimizing'));
			// delete backup copy - maybe this should run in all cases?
			if(isset($this->configData['optAutoAction']) && in_array('backup', $this->configData['optAutoAction'])) {
				$this->addHookAfter('InputfieldFile::processInputDeleteFile', $this, 'deleteBackup');
			}
		}

		// optimize images in auto mode on resize
		if(isset($this->configData['optAutoAction']) && !$config->demo &&
			(in_array('optimize_variations', $this->configData['optAutoAction']) || in_array('optimize_variationsCI3', $this->configData['optAutoAction']))) {
			$this->addHookAfter('ImageSizer::resize', $this, 'checkOptimizeNeeded');
			$this->addHookAfter('Pageimage::size', $this, 'optimizeOnResize');
			if($this->wire('modules')->isInstalled('FieldtypeCroppableImage3') && in_array('optimize_variationsCI3', $this->configData['optAutoAction'])) {
				$this->addHookAfter('ProcessCroppableImage3::executeSave', $this, 'optimizeOnResizeCI3');
			}
		}

	}

	/**
	 * Check for disabled exec functions
	 *
	 */
	public function ___install() {
		$disabled = explode(', ', @ini_get('disable_functions'));
		if(in_array('exec', $disabled)) $this->error('exec functions disabled, web server optimization tools will not work.');
	}

	/**
	 * Removes directory /site/assets/autosmush on module uninstall, from previosu versions
	 *
	 */
	public function ___uninstall() {
		$logFolder = $this->wire('config')->paths->assets . strtolower(__CLASS__);
		if(is_dir($logFolder)) {
			if(wireRmdir($logFolder, true) === false) throw new WireException("{$logFolder} could not be removed");
		}
	}

}
