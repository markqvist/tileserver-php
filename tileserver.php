<?php

/*
 * TileServer.php project
 * ======================
 * https://github.com/klokantech/tileserver-php/
 * Copyright (C) 2014 - Klokan Technologies GmbH
 */

global $config;
$config['serverTitle'] = 'TileServer-php v0.2';
//$config['baseUrls'] = ['t0.server.com', 't1.server.com'];

Router::serve(array(
    '/' => 'Server:getHtml',
    '/test' => 'Server:getInfo',
    '/html' => 'Server:getHtml',
    '/:string.json' => 'Json:getJson',
    '/:string.jsonp' => 'Json:getJsonp',
    '/:string/:number/:number/:number.grid.json' => 'Json:getUTFGrid',
    '/wmts/' => 'Wmts:getCapabilities',
    '/wmts' => 'Wmts:getTile',
    '/wmts/1.0.0/WMTSCapabilities.xml' => 'Wmts:getCapabilities',
    '/:string/:number/:number/:number.:string' => 'Wmts:getTile',
    '/tms' => 'Tms:getCapabilities',
    '/tms/' => 'Tms:getCapabilities',
    '/:string/tms' => 'Tms:getLayerCapabilities',
    '/:string/tms/' => 'Tms:getLayerCapabilities',
    '/:string/tms/:number/:number/:number.:string' => 'Tms:getTile',
));

/**
 * Server base
 */
class Server {

  /**
   * Configuration of TileServer [baseUrls, serverTitle, host]
   * @var array 
   */
  public $config;

  /**
   * Datasets stored in file structure
   * @var array 
   */
  public $fileLayer = array();

  /**
   * Datasets stored in database
   * @var array 
   */
  public $dbLayer = array();

  /**
   * PDO database connection
   * @var object 
   */
  private $db;

  /** sercer.com/ts.php
   * Set config
   */
  public function __construct() {
    $this->config = $GLOBALS['config'];
    if (!isset($this->config['baseUrls'])) {
      //TODO if contains tileserver.php add to path
      $ru = explode('/', $_SERVER['REQUEST_URI']);
      $this->config['baseUrls'][0] = $_SERVER['HTTP_HOST'];
      if (isset($ru[2])) {
        //autodetection for http://server/ or http://server/directory
        //subdirectories must be specified $con
        $this->config['baseUrls'][0] = $this->config['baseUrls'][0] . '/' . $ru[1];
      }
    }
  }

  /**
   * Looks for datasets
   */
  public function setDatasets() {
    $mjs = glob('*/metadata.json');
    $mbts = glob('*.mbtiles');
    if ($mjs) {
      foreach ($mjs as $mj) {
        $layer = $this->metadataFromMetadataJson($mj);
        array_push($this->fileLayer, $layer);
      }
    } elseif ($mbts) {
      foreach ($mbts as $mbt) {
        $this->dbLayer[] = $this->metadataFromMbtiles($mbt);
      }
    } else {
      echo 'Server: No JSON or MBtiles file with metadata';
      die;
    }
  }

  /**
   * Processing params from router <server>/<layer>/<z>/<x>/<y>.ext
   * @param array $params
   */
  public function setParams($params) {
    if (isset($params[1])) {
      $this->layer = $params[1];
    }
    if (isset($params[2])) {
      $this->z = $params[2];
      $this->x = $params[3];
      $this->y = $params[4];
    }
    if (isset($params[5])) {
      $this->ext = $params[5];
    }
  }

  /**
   * Get variable don't independent on sensitivity
   * @param string $key
   * @return boolean
   */
  public function getGlobal($key) {
    $keys[] = $key;
    $keys[] = strtolower($key);
    $keys[] = strtoupper($key);
    $keys[] = ucfirst($key);
    foreach ($keys as $key) {
      if (isset($_GET[$key])) {
        return $_GET[$key];
      }
    }
    return FALSE;
  }

  /**
   * Testing if is a database layer
   * @param string $layer
   * @return boolean
   */
  public function isDBLayer($layer) {
    foreach ($this->dbLayer as $DBLayer) {
      $basename = explode('.', $DBLayer['basename']);
      if ($basename[0] == $layer) {
        return TRUE;
      }
    }
    return false;
  }

  /**
   * Testing if is a file layer
   * @param string $layer
   * @return boolean
   */
  public function isFileLyer($layer) {
    foreach ($this->fileLayer as $DBLayer) {
      if ($DBLayer['basename'] == $layer) {
        return TRUE;
      }
    }
    return false;
  }

  /**
   * 
   * @param string $jsonFileName
   * @return array
   */
  public function metadataFromMetadataJson($jsonFileName) {
    $metadata = json_decode(file_get_contents($jsonFileName), true);
    $metadata = $this->metadataValidation($metadata);
    $metadata['basename'] = str_replace('/metadata.json', '', $jsonFileName);
    return $metadata;
  }

  /**
   * Loads metadata from MBtiles
   * @param string $mbt
   * @return object
   */
  public function metadataFromMbtiles($mbt) {
    $metadata = array();
    $this->DBconnect($mbt);
    $result = $this->db->query('select * from metadata');

    $resultdata = $result->fetchAll();
    foreach ($resultdata as $r) {
      $metadata[$r['name']] = $r['value'];
    }
    $metadata = $this->metadataValidation($metadata);
    $mbt = explode('.', $mbt);
    $metadata['basename'] = $mbt[0];
    return $metadata;
  }

  /**
   * Valids metaJSON
   * @param object $metadata
   * @return object
   */
  public function metadataValidation($metadata) {
    if (array_key_exists('bounds', $metadata)) {
// TODO: Calculate bounds from tiles if bounds is missing - with GlobalMercator
      $metadata['bounds'] = array_map('floatval', explode(',', $metadata['bounds']));
    } else {
      $metadata['bounds'] = array(-180, -85.051128779807, 180, 85.051128779807);
    }
    if (!array_key_exists('profile', $metadata)) {
      $metadata['profile'] = 'mercator';
    }
// TODO: detect format, minzoom, maxzoom, thumb
// scandir() for directory / SQL for mbtiles
    if (array_key_exists('minzoom', $metadata))
      $metadata['minzoom'] = intval($metadata['minzoom']);
    else
      $metadata['minzoom'] = 0;
    if (array_key_exists('maxzoom', $metadata))
      $metadata['maxzoom'] = intval($metadata['maxzoom']);
    else
      $metadata['maxzoom'] = 18;
    if (!array_key_exists('format', $metadata)) {
      $metadata['format'] = 'png';
    }
    /*
      if (!array_key_exists('thumb', $metadata )) {
      $metadata['profile'] = 'mercator';
      }
     */
    return $metadata;
  }

  /**
   * SQLite connection
   * @param string $tileset
   */
  public function DBconnect($tileset) {
    try {
      $this->db = new PDO('sqlite:' . $tileset, '', '', array(PDO::ATTR_PERSISTENT => true));
    } catch (Exception $exc) {
      echo $exc->getTraceAsString();
      die;
    }

    if (!isset($this->db)) {
      header('Content-type: text/plain');
      echo 'Incorrect tileset name: ' . $tileset;
      exit;
    }
  }

  /**
   * Check if file is modified and set Etag headers
   * @param string $filename
   * @return boolean
   */
  public function isModified($filename) {
    $filename = $filename . '.mbtiles';
    $lastModifiedTime = filemtime($filename);
    $eTag = md5_file($filename);
    header("Last-Modified: " . gmdate("D, d M Y H:i:s", $lastModifiedTime) . " GMT");
    header("Etag:" . $eTag);
    if (@strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) == $lastModifiedTime ||
            trim($_SERVER['HTTP_IF_NONE_MATCH']) == $eTag) {
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * Returns tile of dataset
   * @param string $tileset
   * @param integer $z
   * @param integer $y
   * @param integer $x
   * @param string $ext
   */
  public function getTile($tileset, $z, $y, $x, $ext) {
    if ($this->isDBLayer($tileset)) {
      if ($this->isModified($tileset) == TRUE) {
        header('HTTP/1.1 304 Not Modified');
      }
      $this->DBconnect($tileset . '.mbtiles');
      $z = floatval($z);
      $y = floatval($y);
      $x = floatval($x);
      $flip = true;
      if ($flip) {
        $y = pow(2, $z) - 1 - $y;
      }
      $result = $this->db->query('select tile_data as t from tiles where zoom_level=' . $z . ' and tile_column=' . $x . ' and tile_row=' . $y);
      $data = $result->fetchColumn();
      if (!isset($data) || $data === FALSE) {
        $this->getCleanTile();
      } else {
        $result = $this->db->query('select value from metadata where name="format"');
        $resultdata = $result->fetchColumn();
        $format = isset($resultdata) && $resultdata !== FALSE ? $resultdata : 'png';
        if ($format == 'jpg') {
          $format = 'jpeg';
        }
        header('Content-type: image/' . $format);
        echo $data;
      }
    } elseif ($this->isFileLyer($tileset)) {
      $name = './' . $tileset . '/' . $z . '/' . $y . '/' . $x . '.' . $ext;
      if ($fp = @fopen($name, 'rb')) {
        header('Content-Type: image/' . $ext);
        header('Content-Length: ' . filesize($name));
        fpassthru($fp);
        die;
      } else {
        $this->getCleanTile();
      }
    } else {
      echo 'Server: Unknown or not specified dataset';
      die;
    }
  }

  /**
   * Returns clean tile
   */
  public function getCleanTile() {
    $png = imagecreatetruecolor(256, 256);
    imagesavealpha($png, true);
    $trans_colour = imagecolorallocatealpha($png, 0, 0, 0, 127);
    imagefill($png, 0, 0, $trans_colour);
    header('Content-type: image/png');
    imagepng($png);
    die;
  }

  /**
   * Returns tile's UTFGrid
   * @param string $tileset
   * @param integer $z
   * @param integer $y
   * @param integer $x
   */
  public function getUTFGrid($tileset, $z, $y, $x) {
    if ($this->isDBLayer($tileset)) {
      if ($this->isModified($tileset) == TRUE) {
        header('HTTP/1.1 304 Not Modified');
      }
      $this->DBconnect($tileset . '.mbtiles');
      try {
        $result = $this->db->query('SELECT grid FROM grids WHERE tile_column = ' . $x . ' AND tile_row = ' . $y . ' AND zoom_level = ' . $z);
        $data = $result->fetchColumn();
        if (!isset($data) || $data === FALSE) {
          header('Access-Control-Allow-Origin: *');
          echo 'grid({});';
          die;
        } else {
          $grid = gzuncompress($data);
          $grid = substr(trim($grid), 0, -1);

          //adds legend (data) to output
          $grid .= ',"data":{';
          $result = $this->db->query('SELECT key_name as key, key_json as json FROM grid_data WHERE zoom_level=' . $z . ' and tile_column=' . $x . ' and tile_row=' . $y);
          while ($r = $result->fetch(PDO::FETCH_ASSOC)) {
            $grid .= '"' . $r['key'] . '":' . $r['json'] . ',';
          }
          $grid = rtrim($grid, ',') . '}}';
          header('Access-Control-Allow-Origin: *');
          if (isset($_GET['callback'])) {
            header("Content-Type:text/javascript charset=utf-8");
            echo $_GET['callback'] . '(' . $grid . ');';
          } else {
            header("Content-Type:text/javascript; charset=utf-8");
            echo 'grid(' . $grid . ');';
          }
        }
      } catch (PDOException $e) {
        header('Content-type: text/plain');
        print 'Error querying the database: ' . $e->getMessage();
      }
    } else {
      echo 'Server: no MBTiles tileset';
      die;
    }
  }

  /**
   * Returns server info
   */
  public function getInfo() {
    $this->setDatasets();
    $maps = array_merge($this->fileLayer, $this->dbLayer);
    header('Content-Type: text/html;charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . $this->config['serverTitle'] . '</title></head><body>';
    foreach ($maps as $map) {
      $extend = '[';
      foreach ($map['bounds'] as $ext) {
        $extend = $extend . ' ' . $ext;
      }
      $extend = $extend . ' ]';
      if (strpos($map['basename'], 'mbtiles') !== false) {
        echo '<p>Available MBtiles tileset: ' . $map['basename'] . '<br>';
      } else {
        echo '<p>Available file tileset: ' . $map['basename'] . '<br>';
      }
      echo 'Bounds: ' . $extend . '</p>';
    }
    echo '</body></html>';
  }

  /**
   * Returns html viewer
   */
  public function getHtml() {
    $this->setDatasets();
    $maps = array_merge($this->fileLayer, $this->dbLayer);
    header('Content-Type: text/html;charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . $this->config['serverTitle'] . '</title>';
    echo '<link rel="stylesheet" type="text/css" href="http://maptilercdn.s3.amazonaws.com/tileserver.css" />
          <script src="http://maptilercdn.s3.amazonaws.com/tileserver.js"></script><body>
          <script>tileserver("http://' . $this->config['baseUrls'][0] . '/index.jsonp","http://' . $this->config['baseUrls'][0] . '/tms/","http://' . $this->config['baseUrls'][0] . '/wmts/");</script>
          <h1>Welcome to ' . $this->config['serverTitle'] . '</h1>
          <p>This server distributes maps to desktop, web, and mobile applications.</p>
          <p>The mapping data are available as OpenGIS Web Map Tiling Service (OGC WMTS), OSGEO Tile Map Service (TMS), and popular XYZ urls described with TileJSON metadata.</p>';
    if (!isset($maps)) {
      echo '<h3 style="color:darkred;">No maps available yet</h3>
            <p style="color:darkred; font-style: italic;">
            Ready to go - just upload some maps into directory:' . getcwd() . '/ on this server.</p>
            <p>Note: The maps can be a directory with tiles in XYZ format with metadata.json file.<br/>
            You can easily convert existing geodata (GeoTIFF, ECW, MrSID, etc) to this tile structure with <a href="http://www.maptiler.com">MapTiler Cluster</a> or open-source projects such as <a href="http://www.klokan.cz/projects/gdal2tiles/">GDAL2Tiles</a> or <a href="http://www.maptiler.org/">MapTiler</a> or simply upload any maps in MBTiles format made by <a href="http://www.tilemill.com/">TileMill</a>. Helpful is also the <a href="https://github.com/mapbox/mbutil">mbutil</a> tool. Serving directly from .mbtiles files is supported, but with decreased performance.</p>';
    } else {
      echo '<ul>';
      foreach ($maps as $map) {
        echo "<li>" . $map['name'] . '</li>';
      }
      echo '</ul>';
    }
    echo '</body></html>';
  }

}

/**
 * JSON service
 */
class Json extends Server {

  /**
   * Callback for JSONP default grid
   * @var string 
   */
  private $callback = 'grid';

  /**
   * @param array $params
   */
  public $layer = 'index';

  /**
   * @var integer 
   */
  public $z;

  /**
   * @var integer 
   */
  public $y;

  /**
   * @var integer 
   */
  public $x;

  /**
   * @var string 
   */
  public $ext;

  /**
   * 
   * @param array $params
   */
  public function __construct($params) {
    parent::__construct();
    parent::setParams($params);
    parent::setDatasets();
    if (isset($_GET['callback']) && !empty($_GET['callback'])) {
      $this->callback = $_GET['callback'];
    }
  }

  /**
   * Adds metadata about layer
   * @param array $metadata
   * @return array
   */
  public function metadataTileJson($metadata) {
    $metadata['tilejson'] = '2.0.0';
    $metadata['scheme'] = 'xyz';
    $tiles = array();
    foreach ($this->config['baseUrls'] as $url) {
      $tiles[] = 'http://' . $url . '/' . $metadata['basename'] . '/{z}/{x}/{y}.' . $metadata['format'];
    }
    $metadata['tiles'] = $tiles;
    return $metadata;
  }

  /**
   * Creates JSON from array
   * @param string $basename
   * @return string
   */
  private function createJson($basename) {
    $maps = array_merge($this->fileLayer, $this->dbLayer);
    if ($basename == 'index') {
      $output = '[';
      foreach ($maps as $map) {
        $output = $output . json_encode($this->metadataTileJson($map), JSON_UNESCAPED_SLASHES) . ',';
      }
      $output = substr_replace($output, ']', -1);
    } else {
      foreach ($maps as $map) {
        if (strpos($map['basename'], $basename) !== false) {
          $output = json_encode($this->metadataTileJson($map), JSON_UNESCAPED_SLASHES);
          break;
        }
      }
    }
    if (!isset($output)) {
      echo 'TileServer: unknown map ' . $basename;
      die;
    }
    return $output;
  }

  /**
   * Returns JSON with callback
   */
  public function getJson() {
    header('Access-Control-Allow-Origin: *');
    header("Content-Type:application/javascript charset=utf-8");
    echo $this->createJson($this->layer);
  }

  /**
   * Returns JSONP with callback
   */
  public function getJsonp() {
    header('Access-Control-Allow-Origin: *');
    header("Content-Type:text/javascript charset=utf-8");
    echo $this->callback . '(' . $this->createJson($this->layer) . ');';
  }

  /**
   * Returns UTFGrid in JSON format
   */
  public function getUTFGrid() {
    parent::getUTFGrid($this->layer, $this->z, $this->y, $this->x);
  }

}

/**
 * Web map tile service
 */
class Wmts extends Server {

  /**
   * @param array $params
   */
  public $layer;

  /**
   * @var integer 
   */
  public $z;

  /**
   * @var integer 
   */
  public $y;

  /**
   * @var integer 
   */
  public $x;

  /**
   * @var string 
   */
  public $ext;

  /**
   * 
   * @param array $params
   */
  public function __construct($params) {
    parent::__construct();
    parent::setDatasets();
    if (isset($params)) {
      parent::setParams($params);
    }
  }

  /**
   * Returns tilesets getCapabilities 
   */
  public function getCapabilities() {
    header("Content-type: application/xml");
    echo '<?xml version="1.0" encoding="UTF-8" ?>';
    echo '<Capabilities xmlns="http://www.opengis.net/wmts/1.0" xmlns:ows="http://www.opengis.net/ows/1.1" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:gml="http://www.opengis.net/gml" xsi:schemaLocation="http://www.opengis.net/wmts/1.0 http://schemas.opengis.net/wmts/1.0/wmtsGetCapabilities_response.xsd" version="1.0.0">
  <!-- Service Identification -->
  <ows:ServiceIdentification>
    <ows:Title>' . $this->config['serverTitle'] . '</ows:Title>
    <ows:ServiceType>OGC WMTS</ows:ServiceType>
    <ows:ServiceTypeVersion>1.0.0</ows:ServiceTypeVersion>
  </ows:ServiceIdentification>
  <!-- Operations Metadata -->
  <ows:OperationsMetadata>
    <ows:Operation name="GetCapabilities">
      <ows:DCP>
        <ows:HTTP>
          <ows:Get xlink:href="http://' . $this->config['baseUrls'][0] . '/wmts/1.0.0/WMTSCapabilities.xml">
            <ows:Constraint name="GetEncoding">
              <ows:AllowedValues>
                <ows:Value>RESTful</ows:Value>
              </ows:AllowedValues>
            </ows:Constraint>
          </ows:Get>
          <!-- add KVP binding in 10.1 -->
          <ows:Get xlink:href="http://' . $this->config['baseUrls'][0] . '/wmts?">
            <ows:Constraint name="GetEncoding">
              <ows:AllowedValues>
                <ows:Value>KVP</ows:Value>
              </ows:AllowedValues>
            </ows:Constraint>
          </ows:Get>
        </ows:HTTP>
      </ows:DCP>
    </ows:Operation>
    <ows:Operation name="GetTile">
      <ows:DCP>
        <ows:HTTP>
          <ows:Get xlink:href="http://' . $this->config['baseUrls'][0] . '/wmts?">
            <ows:Constraint name="GetEncoding">
              <ows:AllowedValues>
                <ows:Value>RESTful</ows:Value>
              </ows:AllowedValues>
            </ows:Constraint>
          </ows:Get>
          <ows:Get xlink:href="http://' . $this->config['baseUrls'][0] . '/wmts?">
            <ows:Constraint name="GetEncoding">
              <ows:AllowedValues>
                <ows:Value>KVP</ows:Value>
              </ows:AllowedValues>
            </ows:Constraint>
          </ows:Get>
        </ows:HTTP>
      </ows:DCP>
    </ows:Operation>
  </ows:OperationsMetadata>
  <Contents>';
    $maps = array_merge($this->fileLayer, $this->dbLayer);
    $mercator = new GlobalMercator();
    foreach ($maps as $m) {
      if (strpos($m['basename'], '.') !== false) {
        $basename = explode('.', $m['basename']);
      } else {
        $basename = $m['basename'];
      }
      $title = (array_key_exists('name', $m)) ? $m['name'] : $basename;
      $profile = $m['profile'];
      $bounds = $m['bounds'];
      $format = $m['format'];
      $mime = ($format == 'jpg') ? 'image/jpeg' : 'image/png';
      if ($profile == 'geodetic') {
        $tileMatrixSet = "WGS84";
      } else {
        $tileMatrixSet = "GoogleMapsCompatible";
        list( $minx, $miny ) = $mercator->LatLonToMeters($bounds[1], $bounds[0]);
        list( $maxx, $maxy ) = $mercator->LatLonToMeters($bounds[3], $bounds[2]);
        $bounds3857 = array($minx, $miny, $maxx, $maxy);
      }
      echo'<Layer>
      <ows:Title>' . $title . '</ows:Title>
      <ows:Identifier>' . $basename . '</ows:Identifier>';
      echo '<ows:WGS84BoundingBox crs="urn:ogc:def:crs:OGC:2:84">
        <ows:LowerCorner>' . $bounds[0] . ' ' . $bounds[1] . '</ows:LowerCorner>
        <ows:UpperCorner>' . $bounds[2] . ' ' . $bounds[3] . '</ows:UpperCorner>
      </ows:WGS84BoundingBox>
      <Style isDefault="true">
        <ows:Identifier>default</ows:Identifier>
      </Style>
      <Format>' . $mime . '</Format>
      <TileMatrixSetLink>
        <TileMatrixSet>' . $tileMatrixSet . '</TileMatrixSet>
      </TileMatrixSetLink>
      <ResourceURL format="' . $mime . '" resourceType="tile" template="http://'
      . $this->config['baseUrls'][0] . '/' . $basename . '/{TileMatrixSet}/{TileMatrix}/{TileCol}/{TileRow}.' . $format . '"/>
    </Layer>';
    }
    echo '<TileMatrixSet>
      <ows:Title>GoogleMapsCompatible</ows:Title>
      <ows:Abstract>the wellknown "GoogleMapsCompatible" tile matrix set defined by OGC WMTS specification</ows:Abstract>
      <ows:Identifier>GoogleMapsCompatible</ows:Identifier>
      <ows:SupportedCRS>urn:ogc:def:crs:EPSG:6.18:3:3857</ows:SupportedCRS>
      <WellKnownScaleSet>urn:ogc:def:wkss:OGC:1.0:GoogleMapsCompatible</WellKnownScaleSet>
      <TileMatrix>
        <ows:Identifier>0</ows:Identifier>
        <ScaleDenominator>559082264.0287178</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>1</MatrixWidth>
        <MatrixHeight>1</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>1</ows:Identifier>
        <ScaleDenominator>279541132.0143589</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>2</MatrixWidth>
        <MatrixHeight>2</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>2</ows:Identifier>
        <ScaleDenominator>139770566.0071794</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>4</MatrixWidth>
        <MatrixHeight>4</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>3</ows:Identifier>
        <ScaleDenominator>69885283.00358972</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>8</MatrixWidth>
        <MatrixHeight>8</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>4</ows:Identifier>
        <ScaleDenominator>34942641.50179486</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>16</MatrixWidth>
        <MatrixHeight>16</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>5</ows:Identifier>
        <ScaleDenominator>17471320.75089743</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>32</MatrixWidth>
        <MatrixHeight>32</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>6</ows:Identifier>
        <ScaleDenominator>8735660.375448715</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>64</MatrixWidth>
        <MatrixHeight>64</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>7</ows:Identifier>
        <ScaleDenominator>4367830.187724357</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>128</MatrixWidth>
        <MatrixHeight>128</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>8</ows:Identifier>
        <ScaleDenominator>2183915.093862179</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>256</MatrixWidth>
        <MatrixHeight>256</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>9</ows:Identifier>
        <ScaleDenominator>1091957.546931089</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>512</MatrixWidth>
        <MatrixHeight>512</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>10</ows:Identifier>
        <ScaleDenominator>545978.7734655447</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>1024</MatrixWidth>
        <MatrixHeight>1024</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>11</ows:Identifier>
        <ScaleDenominator>272989.3867327723</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>2048</MatrixWidth>
        <MatrixHeight>2048</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>12</ows:Identifier>
        <ScaleDenominator>136494.6933663862</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>4096</MatrixWidth>
        <MatrixHeight>4096</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>13</ows:Identifier>
        <ScaleDenominator>68247.34668319309</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>8192</MatrixWidth>
        <MatrixHeight>8192</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>14</ows:Identifier>
        <ScaleDenominator>34123.67334159654</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>16384</MatrixWidth>
        <MatrixHeight>16384</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>15</ows:Identifier>
        <ScaleDenominator>17061.83667079827</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>32768</MatrixWidth>
        <MatrixHeight>32768</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>16</ows:Identifier>
        <ScaleDenominator>8530.918335399136</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>65536</MatrixWidth>
        <MatrixHeight>65536</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>17</ows:Identifier>
        <ScaleDenominator>4265.459167699568</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>131072</MatrixWidth>
        <MatrixHeight>131072</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>18</ows:Identifier>
        <ScaleDenominator>2132.729583849784</ScaleDenominator>
        <TopLeftCorner>-20037508.34278925 20037508.34278925</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>262144</MatrixWidth>
        <MatrixHeight>262144</MatrixHeight>
      </TileMatrix>
    </TileMatrixSet>
    <TileMatrixSet>
      <ows:Identifier>WGS84</ows:Identifier>
      <ows:Title>GoogleCRS84Quad</ows:Title>
      <ows:SupportedCRS>urn:ogc:def:crs:EPSG:6.3:4326</ows:SupportedCRS>
      <ows:BoundingBox crs="urn:ogc:def:crs:EPSG:6.3:4326">
        <LowerCorner>-180.000000 -90.000000</LowerCorner>
        <UpperCorner>180.000000 90.000000</UpperCorner>
      </ows:BoundingBox>
      <WellKnownScaleSet>urn:ogc:def:wkss:OGC:1.0:GoogleCRS84Quad</WellKnownScaleSet>
      <TileMatrix>
        <ows:Identifier>0</ows:Identifier>
        <ScaleDenominator>279541132.01435887813568115234</ScaleDenominator>
        <TopLeftCorner>90.000000 -180.000000</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>2</MatrixWidth>
        <MatrixHeight>1</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>1</ows:Identifier>
        <ScaleDenominator>139770566.00717943906784057617</ScaleDenominator>
        <TopLeftCorner>90.000000 -180.000000</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>4</MatrixWidth>
        <MatrixHeight>2</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>2</ows:Identifier>
        <ScaleDenominator>69885283.00358971953392028809</ScaleDenominator>
        <TopLeftCorner>90.000000 -180.000000</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>8</MatrixWidth>
        <MatrixHeight>4</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>3</ows:Identifier>
        <ScaleDenominator>34942641.50179485976696014404</ScaleDenominator>
        <TopLeftCorner>90.000000 -180.000000</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>16</MatrixWidth>
        <MatrixHeight>8</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>4</ows:Identifier>
        <ScaleDenominator>17471320.75089742988348007202</ScaleDenominator>
        <TopLeftCorner>90.000000 -180.000000</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>32</MatrixWidth>
        <MatrixHeight>16</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>5</ows:Identifier>
        <ScaleDenominator>8735660.37544871494174003601</ScaleDenominator>
        <TopLeftCorner>90.000000 -180.000000</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>64</MatrixWidth>
        <MatrixHeight>32</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>6</ows:Identifier>
        <ScaleDenominator>4367830.18772435747087001801</ScaleDenominator>
        <TopLeftCorner>90.000000 -180.000000</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>128</MatrixWidth>
        <MatrixHeight>64</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>7</ows:Identifier>
        <ScaleDenominator>2183915.09386217873543500900</ScaleDenominator>
        <TopLeftCorner>90.000000 -180.000000</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>256</MatrixWidth>
        <MatrixHeight>128</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>8</ows:Identifier>
        <ScaleDenominator>1091957.54693108936771750450</ScaleDenominator>
        <TopLeftCorner>90.000000 -180.000000</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>512</MatrixWidth>
        <MatrixHeight>256</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>9</ows:Identifier>
        <ScaleDenominator>545978.77346554468385875225</ScaleDenominator>
        <TopLeftCorner>90.000000 -180.000000</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>1024</MatrixWidth>
        <MatrixHeight>512</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>10</ows:Identifier>
        <ScaleDenominator>272989.38673277234192937613</ScaleDenominator>
        <TopLeftCorner>90.000000 -180.000000</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>2048</MatrixWidth>
        <MatrixHeight>1024</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>11</ows:Identifier>
        <ScaleDenominator>136494.69336638617096468806</ScaleDenominator>
        <TopLeftCorner>90.000000 -180.000000</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>4096</MatrixWidth>
        <MatrixHeight>2048</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>12</ows:Identifier>
        <ScaleDenominator>68247.34668319308548234403</ScaleDenominator>
        <TopLeftCorner>90.000000 -180.000000</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>8192</MatrixWidth>
        <MatrixHeight>4096</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>13</ows:Identifier>
        <ScaleDenominator>34123.67334159654274117202</ScaleDenominator>
        <TopLeftCorner>90.000000 -180.000000</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>16384</MatrixWidth>
        <MatrixHeight>8192</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>14</ows:Identifier>
        <ScaleDenominator>17061.83667079825318069197</ScaleDenominator>
        <TopLeftCorner>90.000000 -180.000000</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>32768</MatrixWidth>
        <MatrixHeight>16384</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>15</ows:Identifier>
        <ScaleDenominator>8530.91833539912659034599</ScaleDenominator>
        <TopLeftCorner>90.000000 -180.000000</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>65536</MatrixWidth>
        <MatrixHeight>32768</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>16</ows:Identifier>
        <ScaleDenominator>4265.45916769956329517299</ScaleDenominator>
        <TopLeftCorner>90.000000 -180.000000</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>131072</MatrixWidth>
        <MatrixHeight>65536</MatrixHeight>
      </TileMatrix>
      <TileMatrix>
        <ows:Identifier>17</ows:Identifier>
        <ScaleDenominator>2132.72958384978574031265</ScaleDenominator>
        <TopLeftCorner>90.000000 -180.000000</TopLeftCorner>
        <TileWidth>256</TileWidth>
        <TileHeight>256</TileHeight>
        <MatrixWidth>262144</MatrixWidth>
        <MatrixHeight>131072</MatrixHeight>
      </TileMatrix>
    </TileMatrixSet>
  </Contents>
  <ServiceMetadataURL xlink:href="http://' . $this->config['baseUrls'][0] . '/wmts/1.0.0/WMTSCapabilities.xml"/>
</Capabilities>';
  }

  /**
   * Returns tile via WMTS specification
   */
  public function getTile() {
    $request = $this->getGlobal('Request');
    if ($request) {
      if (strpos('/', $_GET['Format']) !== FALSE) {
        $format = explode('/', $_GET['Format']);
      } else {
        $format = $this->getGlobal('Format');
      }
      parent::getTile($this->getGlobal('Layer'), $this->getGlobal('TileMatrix'), $this->getGlobal('TileRow'), $this->getGlobal('TileCol'), $format[1]);
    } else {
      parent::getTile($this->layer, $this->z, $this->y, $this->x, $this->ext);
    }
  }

}

/**
 * Tile map service
 */
class Tms extends Server {

  /**
   * @param array $params
   */
  public $layer;

  /**
   * @var integer 
   */
  public $z;

  /**
   * @var integer 
   */
  public $y;

  /**
   * @var integer 
   */
  public $x;

  /**
   * @var string 
   */
  public $ext;

  /**
   * 
   * @param array $params
   */
  public function __construct($params) {
    parent::__construct();
    parent::setParams($params);
    parent::setDatasets();
  }

  /**
   * Returns getCapabilities metadata request
   */
  public function getCapabilities() {
    $maps = array_merge($this->fileLayer, $this->dbLayer);
    header("Content-type: application/xml");
    echo'<TileMapService version="1.0.0"><TileMaps>';
    foreach ($maps as $m) {
      $basename = $m['basename'];
      $title = (array_key_exists('name', $m) ) ? $m['name'] : $basename;
      $profile = $m['profile'];
      if ($profile == 'geodetic') {
        $srs = "EPSG:4326";
      } else {
        $srs = "EPSG:3857";
        echo '<TileMap title="' . $title . '" srs="' . $srs
        . '" type="InvertedTMS" ' . 'profile="global-' . $profile
        . '" href="http://' . $this->config['baseUrls'][0] . '/' . $basename . '/tms" />';
      }
    }
    echo '</TileMaps></TileMapService>';
  }

  /**
   * Prints metadata about layer
   */
  public function getLayerCapabilities() {
    $maps = array_merge($this->fileLayer, $this->dbLayer);
    foreach ($maps as $map) {
      if (strpos($map['basename'], $this->layer) !== false) {
        $m = $map;
        break;
      }
    }
    $title = (array_key_exists('name', $m)) ? $m['name'] : $m['basename'];
    $description = (array_key_exists('description', $m)) ? $m['description'] : "";
    $bounds = $m['bounds'];
    if ($m['profile'] == 'geodetic') {
      $srs = "EPSG:4326";
      $originx = -180.0;
      $originy = -90.0;
      $initialResolution = 0.703125;
    } else {
      $srs = "EPSG:3857";
      $originx = -20037508.342789;
      $originy = -20037508.342789;
      $mercator = new GlobalMercator();
      list( $minx, $miny ) = $mercator->LatLonToMeters($bounds[1], $bounds[0]);
      list( $maxx, $maxy ) = $mercator->LatLonToMeters($bounds[3], $bounds[2]);
      $bounds = array($minx, $miny, $maxx, $maxy);
      $initialResolution = 156543.03392804062;
    }
    $mime = ($m['format'] == 'jpg') ? 'image/jpeg' : 'image/png';
    header("Content-type: application/xml");
    echo '<TileMap version="1.0.0" tilemapservice="http://' . $this->config['baseUrls'][0] . '/' . $m['basename'] . '" type="InvertedTMS">
  <Title>' . htmlspecialchars($title) . '</Title>
  <Abstract>' . htmlspecialchars($description) . '</Abstract>
  <SRS>' . $srs . '</SRS>
  <BoundingBox minx="' . $bounds[0] . '" miny="' . $bounds[1] . '" maxx="' . $bounds[2] . '" maxy="' . $bounds[3] . '" />
  <Origin x="' . $originx . '" y="' . $originy . '"/>
  <TileFormat width="256" height="256" mime-type="' . $mime . '" extension="' . $m['format'] . '"/>
  <TileSets profile="global-' . $m['profile'] . '">';
    for ($zoom = $m['minzoom']; $zoom < $m['maxzoom'] + 1; $zoom++) {
      echo '<TileSet href="http://' . $this->config['baseUrls'] [0] . '/' . $m['basename'] . '/' . $zoom . '" units-per-pixel="' . $initialResolution / pow(2, $zoom) . '" order="' . $zoom . '" />';
    }
    echo'</TileSets></TileMap>';
  }

  /**
   * Process getTile request
   */
  public function getTile() {
    parent::getTile($this->layer, $this->z, $this->y, $this->x, $this->ext);
  }

}

/*
  GlobalMapTiles - part of Aggregate Map Tools
  Version 1.0
  Copyright (c) 2009 The Bivings Group
  All rights reserved.
  Author: John Bafford

  http://www.bivings.com/
  http://bafford.com/softare/aggregate-map-tools/

  Based on GDAL2Tiles / globalmaptiles.py
  Original python version Copyright (c) 2008 Klokan Petr Pridal. All rights reserved.
  http://www.klokan.cz/projects/gdal2tiles/

  Permission is hereby granted, free of charge, to any person obtaining a
  copy of this software and associated documentation files (the "Software"),
  to deal in the Software without restriction, including without limitation
  the rights to use, copy, modify, merge, publish, distribute, sublic ense,
  and/or sell copies of the Software, and to permit persons to whom the
  Software is furnished to do so, subject to the following conditions:

  The abov
  e copyright notice and this permission notice shall be included
  in all copies or substantial portions of the Software.

  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
  OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
  THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
  FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
  DEALINGS IN THE SOFTWARE.
 */

class GlobalMercator {

  var $tileSize;
  var $initialResolution;
  var $originShift;

//Initialize the TMS Global Mercator pyramid
  function __construct($tileSize = 256) {
    $this->tileSize = $tileSize;
    $this->initialResolution = 2 * M_PI * 6378137 / $this->tileSize;
# 156543.03392804062 for tileSize 256 Pixels
    $this->originShift = 2 * M_PI * 6378137 / 2.0;
# 20037508.342789244
  }

//Converts given lat/lon in WGS84 Datum to XY in Spherical Mercator EPSG:900913
  function LatLonToMeters($lat, $lon) {
    $mx = $lon * $this->originShift / 180.0;
    $my = log(tan((90 + $lat) * M_PI / 360.0)) / (M_PI / 180.0);

    $my *= $this->originShift / 180.0;

    return array($mx, $my);
  }

//Converts XY point from Spherical Mercator EPSG:900913 to lat/lon in WGS84 Datum
  function MetersToLatLon($mx, $my) {
    $lon = ($mx / $this->originShift) * 180.0;
    $lat = ($my / $this->originShift) * 180.0;

    $lat = 180 / M_PI * (2 * atan(exp($lat * M_PI / 180.0)) - M_PI / 2.0);

    return array($lat, $lon);
  }

//Converts pixel coordinates in given zoom level of pyramid to EPSG:900913
  function PixelsToMeters($px, $py, $zoom) {
    $res = $this->Resolution($zoom);
    $mx = $px * $res - $this->originShift;
    $my = $py * $res - $this->originShift;

    return array($mx, $my);
  }

//Converts EPSG:900913 to pyramid pixel coordinates in given zoom level
  function MetersToPixels($mx, $my, $zoom) {
    $res = $this->Resolution($zoom);

    $px = ($mx + $this->originShift) / $res;
    $py = ($my + $this->originShift) / $res;

    return array($px, $py);
  }

//Returns a tile covering region in given pixel coordinates
  function PixelsToTile($px, $py) {
    $tx = ceil($px / $this->tileSize) - 1;
    $ty = ceil($py / $this->tileSize) - 1;

    return array($tx, $ty);
  }

//Returns tile for given mercator coordinates
  function MetersToTile($mx, $my, $zoom) {
    list($px, $py) = $this->MetersToPixels($mx, $my, $zoom);

    return $this->PixelsToTile($px, $py);
  }

//Returns bounds of the given tile in EPSG:900913 coordinates
  function TileBounds($tx, $ty, $zoom) {
    list($minx, $miny) = $this->PixelsToMeters($tx * $this->tileSize, $ty * $this->tileSize, $zoom);
    list($maxx, $maxy) = $this->PixelsToMeters(($tx + 1) * $this->tileSize, ($ty + 1) * $this->tileSize, $zoom);

    return array($minx, $miny, $maxx, $maxy);
  }

//Returns bounds of the given tile in latutude/longitude using WGS84 datum
  function TileLatLonBounds($tx, $ty, $zoom) {
    $bounds = $this->TileBounds($tx, $ty, $zoom);

    list($minLat, $minLon) = $this->MetersToLatLon($bounds[0], $bounds[1]);
    list($maxLat, $maxLon) = $this->MetersToLatLon($bounds[2], $bounds[3]);

    return array($minLat, $minLon, $maxLat, $maxLon);
  }

//Resolution (meters/pixel) for given zoom level (measured at Equator)
  function Resolution($zoom) {
    return $this->initialResolution / (1 < $zoom);
  }

}

/**
 * Simple router
 */
class Router {

  /**
   * @param array $routes
   */
  public static function serve($routes) {
    $request_method = strtolower($_SERVER['REQUEST_METHOD']);
    $path_info = '/';
    if (!empty($_SERVER['PATH_INFO'])) {
      $path_info = $_SERVER['PATH_INFO'];
    } else if (!empty($_SERVER['ORIG_PATH_INFO']) && $_SERVER['ORIG_PATH_INFO'] !== '/tileserver.php') {
      $path_info = $_SERVER['ORIG_PATH_INFO'];
    } else {
      if (!empty($_SERVER['REQUEST_URI'])) {
        $path_info = (strpos($_SERVER['REQUEST_URI'], '?') > 0) ? strstr($_SERVER['REQUEST_URI'], '?', true) : $_SERVER['REQUEST_URI'];
      }
    }
    $discovered_handler = null;
    $regex_matches = array();
    if (isset($routes[$path_info])) {
      $discovered_handler = $routes[$path_info];
    } else if ($routes) {
      $tokens = array(
          ':string' => '([a-zA-Z]+)',
          ':number' => '([0-9]+)',
          ':alpha' => '([a-zA-Z0-9-_]+)'
      );
      foreach ($routes as $pattern => $handler_name) {
        $pattern = strtr($pattern, $tokens);
        if (preg_match('#^/?' . $pattern . '/?$#', $path_info, $matches)) {
          $discovered_handler = $handler_name;
          $regex_matches = $matches;
          break;
        }
      }
    }
    $handler_instance = null;
    if ($discovered_handler) {
      if (is_string($discovered_handler)) {
        if (strpos($discovered_handler, ':') !== false) {
          $discoverered_class = explode(':', $discovered_handler);
          $discoverered_method = explode(':', $discovered_handler);
          $handler_instance = new $discoverered_class[0]($regex_matches);
          call_user_func(array($handler_instance, $discoverered_method[1]));
        } else {
          $handler_instance = new $discovered_handler($regex_matches);
        }
      } elseif (is_callable($discovered_handler)) {
        $handler_instance = $discovered_handler();
      }
    } else {
//default page
      $handler_instance = new Server;
      $handler_instance->getHtml();
    }
  }

}
