<?php

class ImportDramaCool {

  public $Video_model = null;
  public $Series_model = null;
  public $Genre_model = null;
  public $Series_Genre_model = null;
  public $Video_Url_model = null;
  private $_config = null;

  function __construct() {
    $CI = & get_instance();
    $CI->load->model('Video_model', NULL, TRUE);
    $CI->load->model('Series_model', NULL, TRUE);
    $CI->load->model('Genre_model', NULL, TRUE);
    $CI->load->model('Series_Genre_model', NULL, TRUE);
    $CI->load->model('Video_Url_model', NULL, TRUE);

    $video_model = $CI->Video_model;
    $this->Video_model = $video_model::getInstance();
    $series_model = $CI->Series_model;
    $this->Series_model = $series_model::getInstance();
    $genre_model = $CI->Genre_model;
    $this->Genre_model = $genre_model::getInstance();
    $series_genre_model = $CI->Series_Genre_model;
    $this->Series_Genre_model = $series_genre_model::getInstance();
    $video_url_model = $CI->Video_Url_model;
    $this->Video_Url_model = $video_url_model::getInstance();


    $this->_config = $CI->config->config;
  }
  public function importFromCountryUrl($url, $extraData = array()) {
    //echo "importFromCountryUrl: ".$url."\n";
    $type = isset($extraData['type']) ? $extraData['type'] : VIDEO_TYPE_DRAMA;
    switch ($type) {
      case VIDEO_TYPE_DRAMA:
      case VIDEO_TYPE_SHOW:
        $seriesLinks = $this->getAllSeriesLinkByChar($type);
        break;
      case VIDEO_TYPE_MOVIE:
        $seriesLinks = $this->getMovieSeriesLink($url);
        break;
      default:
        break;
    }
    if ($seriesLinks) {
      foreach ($seriesLinks as $seriesLink) {
        $id = $this->importFromSeriesUrl($seriesLink, $extraData);
      }
    }
    $ret['id'] = 1;
    return $ret;
  }

  public function importFromSeriesUrl($url, $extraData = array()) {
    echo "\nimportFromSeriesUrl: ".$url."\n";
    $ret = array();
    $seriesData = $this->getSeriesData($url);
    if ($seriesData) {
      if ($seriesData['videos']) {
        foreach ($seriesData['videos'] as $videoObj) {
          $videoUrl = $videoObj['original_url'];
          $this->importFromVideoUrl($videoUrl, $extraData);
        }
      }
    }
    $ret['id'] = 1;
    return $ret;
  }
  public function importFromVideoUrl($url, $extraData = array()) {
    $command = isset($extraData['command']) ? $extraData['command'] : '';
    $type = isset($extraData['type']) ? $extraData['type'] : VIDEO_TYPE_DRAMA;
    $videoId = 0;
    $ret = array();
    $ret['id'] = 0;
    $videoData = $this->getVideoData($url, $command);
    echo "\n";
    if($command=='update_sub'){
      if($videoData['has_sub']==1){
        echo "update sub: " . $url . "\n";
      }else{
        echo "raw \n";
        return false;
      }
    }
    echo "importFromVideoUrl: " . $url . "\n";
    if ($videoData) {
      //series
      $seriesTitle = $videoData['series_title'];
      $seriesLink = $videoData['series_link'];
      $dbSeries = $this->Series_model->getByTitle($seriesTitle);
      if ($dbSeries) {
        $seriesId = $dbSeries['id'];
        $updateSeriesData = array();
        if(empty($dbSeries['is_complete'])){
          $tmpSeriesData = $this->getSeriesData($seriesLink);
          $updateSeriesData['is_complete'] = $tmpSeriesData['is_complete'];
          $this->Series_model->update($seriesId, $updateSeriesData);
        }

      } else {
        $seriesData = $this->getSeriesData($seriesLink);
        $seriesThumbnail = saveImageFromSite($seriesData['thumbnail'], SERIE_IMAGE_THUMBNAIL_PATH);
        $seriesData['thumbnail'] = $seriesThumbnail;
        $seriesData['status'] = STATUS_SHOW;
        $seriesData['type'] = $type;
        $seriesId = $this->Series_model->insert($seriesData);
        if ($seriesData['genre']) {
          foreach ($seriesData['genre'] as $genreId => $genreName) {
            $seriesGenreData = array();
            $seriesGenreData['genre_id'] = $genreId;
            $seriesGenreData['series_id'] = $seriesId;
            $this->Series_Genre_model->insert($seriesGenreData);
          }
        }
      }
      //end series
      //video
      $videoData['series_id'] = $seriesId;
      $videoByOriginalUrl = $this->Video_model->getVideoByOriginalUrl($url);
      if (!$videoByOriginalUrl) {
        $videoData['status'] = (isset($extraData['status']) && $extraData['status'] == STATUS_SHOW) ?  STATUS_SHOW : STATUS_WAIT_FOR_APPROVE;
        $videoId = $this->Video_model->insert($videoData);
      } else {
        $videoId = $videoByOriginalUrl['id'];
        if($videoData['has_sub']){
          $videoByOriginalUrl['has_sub'] = $videoData['has_sub'];
          $this->Video_model->update($videoId, $videoByOriginalUrl);
        }
      }
      $this->Video_Url_model->deleteByVideoId($videoId);
      //hd 720
      if ($videoData['hdIframe']) {//get
        $streamingUrl = $this->getVideoSourceFromIframe($videoData['hdIframe'], 'hd');
        $this->_insertStreamingUrl($videoId, $streamingUrl, SERVER_TYPE_HD, VIDEO_TYPE_720, '');
      }
      $streamingUrl = '';
      $hasIframe = false;
      //360
      if ($videoData['standardIframe']) {//default => Iframe
        $iframePlayerLink = $videoData['coolIframe'];
        if($iframePlayerLink){
          $hasIframe = TRUE;
        }
        $streamingUrl = $this->getVideoSourceFromIframe($iframePlayerLink, 'standard');
        $this->_insertStreamingUrl($videoId, $streamingUrl, SERVER_TYPE_STANDARD, VIDEO_TYPE_360, $iframePlayerLink);
      }
      if ($videoData['coolIframe']) {//GG
        $iframePlayerLink = $videoData['coolIframe'];
        if($iframePlayerLink){
          $hasIframe = TRUE;
        }
        $streamingUrl = $videoData['coolStreamingUrl'];//$this->getVideoSourceFromIframe($iframePlayerLink, 'cool');
        $this->_insertStreamingUrl($videoId, $streamingUrl, SERVER_TYPE_COOL, VIDEO_TYPE_360, $iframePlayerLink);
      }
      if ($videoData['mp4Iframe']) {//iframe
        $iframePlayerLink = $videoData['mp4Iframe'];
        if($iframePlayerLink){
          $hasIframe = TRUE;
        }
        $streamingUrl = $this->getVideoSourceFromIframe($iframePlayerLink, 'mp4');
        $this->_insertStreamingUrl($videoId, $streamingUrl, SERVER_TYPE_MP4, VIDEO_TYPE_360, $iframePlayerLink);
      }
      if ($videoData['server1Iframe']) {//iframe
        $iframePlayerLink = $videoData['server1Iframe'];
        if($iframePlayerLink){
          $hasIframe = TRUE;
        }
        $streamingUrl = $this->getVideoSourceFromIframe($iframePlayerLink, 'server1');
        $this->_insertStreamingUrl($videoId, $streamingUrl, SERVER_TYPE_SERVER1, VIDEO_TYPE_360, $iframePlayerLink);
      }
      if (!$streamingUrl && !$hasIframe) {
        echo "-------------No streaming file--------\n";
      }

      //end video
    }
    $ret['id'] = $videoId;
    return $ret;
  }
  public function updateStreamingInLog($videoId, $url, $hasSub=0) {
    $ret = array();
    $videoUrlArr = $this->Video_Url_model->getAllVideoUrlByVideoId($videoId);
    $videoData = $this->getVideoData($url);
    if($hasSub==0 && $videoData['has_sub']==1){
      $updateData = array('has_sub' => 1);
      $this->Video_model->update($videoId, $updateData);
    }
    //hd 720
    if ($videoData['hdIframe']) {//get
      $streamingUrl = $this->getVideoSourceFromIframe($videoData['hdIframe'], 'hd');
      if(isset($videoUrlArr[SERVER_TYPE_HD])){
        $tmpData = $videoUrlArr[SERVER_TYPE_HD];
        $this->_updateStreamingUrl($tmpData['id'], $videoId, $streamingUrl, SERVER_TYPE_HD, VIDEO_TYPE_720, '');
        $ret[$tmpData['id']] = $streamingUrl;
      }else{
        $sId = $this->_insertStreamingUrl($videoId, $streamingUrl, SERVER_TYPE_HD, VIDEO_TYPE_720, '');
        $ret[$sId] = $streamingUrl;
      }
    }
    $streamingUrl = '';
    $hasIframe = false;
    //360
    if ($videoData['standardIframe']) {//default => Google
      $streamingUrl = $this->getVideoSourceFromIframe($videoData['standardIframe'], 'standard');
      if(isset($videoUrlArr[SERVER_TYPE_STANDARD])){
        $tmpData = $videoUrlArr[SERVER_TYPE_STANDARD];
        if($streamingUrl){
          $this->_updateStreamingUrl($tmpData['id'], $videoId, $streamingUrl, SERVER_TYPE_STANDARD, VIDEO_TYPE_360, $videoData['standardIframe']);
          $ret[$tmpData['id']] = $streamingUrl;
        }else{
          //$this->Video_Url_model->delete($tmpData['id']);
        }
      }else{
        if($streamingUrl) {
          $sId = $this->_insertStreamingUrl($videoId, $streamingUrl, SERVER_TYPE_STANDARD, VIDEO_TYPE_360, $videoData['standardIframe']);
          $ret[$sId] = $streamingUrl;
        }
      }
    }elseif(isset($videoUrlArr[SERVER_TYPE_STANDARD])){
      $tmpData = $videoUrlArr[SERVER_TYPE_STANDARD];
      //$this->Video_Url_model->delete($tmpData['id']);
    }
    if ($videoData['coolIframe']) {//iframe
      $iframePlayerLink = $videoData['coolIframe'];
      $streamingUrl = $videoData['coolStreamingUrl'];//$this->getVideoSourceFromIframe($iframePlayerLink, 'cool');
      if(isset($videoUrlArr[SERVER_TYPE_COOL])){
        $tmpData = $videoUrlArr[SERVER_TYPE_COOL];
        $this->_updateStreamingUrl($tmpData['id'], $videoId, $streamingUrl, SERVER_TYPE_COOL, VIDEO_TYPE_360, $iframePlayerLink);
      }else{
        $this->_insertStreamingUrl($videoId, $streamingUrl, SERVER_TYPE_COOL, VIDEO_TYPE_360, $iframePlayerLink);
      }
    }
    if ($videoData['mp4Iframe']) {//iframe
      $iframePlayerLink = $videoData['mp4Iframe'];
      $streamingUrl = $this->getVideoSourceFromIframe($iframePlayerLink, 'mp4');
      if(isset($videoUrlArr[SERVER_TYPE_MP4])){
        $tmpData = $videoUrlArr[SERVER_TYPE_MP4];
        $this->_updateStreamingUrl($tmpData['id'], $videoId, $streamingUrl, SERVER_TYPE_MP4, VIDEO_TYPE_360, '');
      }else{
        $this->_insertStreamingUrl($videoId, $streamingUrl, SERVER_TYPE_MP4, VIDEO_TYPE_360, $iframePlayerLink);
      }
    }
    if ($videoData['server1Iframe']) {//iframe
      $iframePlayerLink = $videoData['server1Iframe'];
      $streamingUrl = $this->getVideoSourceFromIframe($iframePlayerLink, 'server1');
      if(isset($videoUrlArr[SERVER_TYPE_SERVER1])){
        $tmpData = $videoUrlArr[SERVER_TYPE_SERVER1];
        $this->_updateStreamingUrl($tmpData['id'], $videoId, $streamingUrl, SERVER_TYPE_SERVER1, VIDEO_TYPE_360, '');
      }else{
        $this->_insertStreamingUrl($videoId, $streamingUrl, SERVER_TYPE_SERVER1, VIDEO_TYPE_360, $iframePlayerLink);
      }
    }
    return $ret;
  }
  public function updateStreaming($videoId, $url, $hasSub = true) {
    echo "updateStreaming: ".$videoId. ": " . $url . "\n";
    $videoData = $this->getVideoData($url);
    if($hasSub==0 && $videoData['has_sub']==1){
      $updateData = array('has_sub' => 1);
      $this->Video_model->update($videoId, $updateData);
    }
    $this->Video_Url_model->deleteByVideoId($videoId);
    //hd 720
    if ($videoData['hdIframe']) {//get
      $streamingUrl = $this->getVideoSourceFromIframe($videoData['hdIframe'], 'hd');
      $this->_insertStreamingUrl($videoId, $streamingUrl, SERVER_TYPE_HD, VIDEO_TYPE_720, '');
    }
    $streamingUrl = '';
    $hasIframe = false;
    //360
    if ($videoData['standardIframe']) {//default => Iframe
      $iframePlayerLink = $videoData['coolIframe'];
      if($iframePlayerLink){
        $hasIframe = TRUE;
      }
      $streamingUrl = $this->getVideoSourceFromIframe($iframePlayerLink, 'standard');
      $this->_insertStreamingUrl($videoId, $streamingUrl, SERVER_TYPE_STANDARD, VIDEO_TYPE_360, $iframePlayerLink);
    }
    if ($videoData['coolIframe']) {//GG
      $iframePlayerLink = $videoData['coolIframe'];
      if($iframePlayerLink){
        $hasIframe = TRUE;
      }
      $streamingUrl = $videoData['coolStreamingUrl'];//$this->getVideoSourceFromIframe($iframePlayerLink, 'cool');
      $this->_insertStreamingUrl($videoId, $streamingUrl, SERVER_TYPE_COOL, VIDEO_TYPE_360, $iframePlayerLink);
    }
    if ($videoData['mp4Iframe']) {//iframe
      $iframePlayerLink = $videoData['mp4Iframe'];
      $streamingUrl = $this->getVideoSourceFromIframe($iframePlayerLink, 'mp4');
      if($iframePlayerLink){
        $hasIframe = TRUE;
      }
      $this->_insertStreamingUrl($videoId, $streamingUrl, SERVER_TYPE_MP4, VIDEO_TYPE_360, $iframePlayerLink);
    }
    if ($videoData['server1Iframe']) {//iframe
      $iframePlayerLink = $videoData['server1Iframe'];
      $streamingUrl = $this->getVideoSourceFromIframe($iframePlayerLink, 'server1');
      if($iframePlayerLink){
        $hasIframe = TRUE;
      }
      $this->_insertStreamingUrl($videoId, $streamingUrl, SERVER_TYPE_SERVER1, VIDEO_TYPE_360, $iframePlayerLink);
    }
    if (!$streamingUrl && !$hasIframe) {
      echo "-------------No streaming file--------\n";
    }
    return TRUE;
  }
  private function _insertStreamingUrl($videoId, $streamingUrl, $serverType, $formatType = VIDEO_TYPE_360, $iframeUrl=''){
    $videoUrlData = array();
    $videoUrlData['video_id'] = $videoId;
    $videoUrlData['type'] = $formatType;
    $videoUrlData['server_type'] = $serverType;
    $videoUrlData['streaming_url'] = $streamingUrl;
    if($iframeUrl){
      $videoUrlData['iframe_url'] = $iframeUrl;
    }
    $id = 0;
    if($iframeUrl || $streamingUrl){
      $id = $this->Video_Url_model->insert($videoUrlData);
    }

    return $id;
  }
  private function _updateStreamingUrl($urlId, $videoId, $streamingUrl, $serverType, $formatType = VIDEO_TYPE_360, $iframeUrl=''){
    $videoUrlData = array();
    $videoUrlData['video_id'] = $videoId;
    $videoUrlData['type'] = $formatType;
    $videoUrlData['server_type'] = $serverType;
    $videoUrlData['streaming_url'] = $streamingUrl;
    if($iframeUrl){
      $videoUrlData['iframe_url'] = $iframeUrl;
    }
    if($iframeUrl || $streamingUrl) {
      $this->Video_Url_model->update($urlId, $videoUrlData);
    }
  }
  public function importHomePage($url, $extraData = array()){
    $page = isset($extraData['page']) ? $extraData['page'] : 1;
    for($p = 1; $p<=$page; $p++){
      $pageUrl = $url.$p;
      echo "importHomePage: " . $pageUrl . "\n";
      $contentUrl = getFileContent($pageUrl);
      $htmlAll = str_get_html($contentUrl);
      if ($htmlAll) {
        $episodeBlocks = $htmlAll->find('div#view-large', 0)->find('div.thumbnail-body-item');
        if($episodeBlocks){
          foreach($episodeBlocks as $episodeObj){
            $link = $episodeObj->find('a', 0)->href;
            $link = DRAMA_COOL_SITE_URL.$link;
            $this->importFromVideoUrl($link, $extraData);
          }
        }
      }
    }
  }
  public function getVideoData($url, $command='') {
    $videoData = array();
    $videoData['original_url'] = $url;
    $contentUrl = getFileContent($url);
    $htmlAll = str_get_html($contentUrl);
    if ($htmlAll) {
      //series
      $seriesDataFromSite = $htmlAll->find('div.title_catergory', 0)->find('a', 0);
      $seriesTitle = trimtext($seriesDataFromSite->plaintext);
      $seriesLink = $seriesDataFromSite->href;
      $videoData['series_title'] = $seriesTitle;
      $videoData['series_link'] = $seriesLink;
      $title = trimtext($htmlAll->find('h1.title', 0)->plaintext);
      $episodeBlocks = $htmlAll->find('div.view-detail', 0)->find('div.row-9dr');
      $videoData['title'] = $title;
      if ($episodeBlocks) {
        foreach ($episodeBlocks as $epBlock) {
          $tmpTitle = trimtext($epBlock->find('div.title_s_az', 0)->plaintext);
          $time = trimtext($epBlock->find('span.timeago', 0)->plaintext);
          $tmpTitle = trimtext(str_replace($time, '', $tmpTitle));
          if ($tmpTitle == $title) {
            $subStatus = strtolower(trimtext($epBlock->find('div.country_s_az', 0)->plaintext));
            $subStatus = $subStatus == 'sub' ? 1 : 0;
            $itemLink = $epBlock->find('a', 0)->href;
            $publishDate = format2DateTime($time);
            $videoData['publish_date'] = $publishDate;
            $videoData['has_sub'] = $subStatus;
            $videoData['episode'] = $this->_getLastNumberInString($tmpTitle);
          }
        }
      }
      $htmlObject1 = $htmlAll->find('div.desc-detail-ep-film', 0);
      $hdServer = $htmlObject1->find('div#serverHD', 0);
      $mobileServer = $htmlObject1->find('div#servermobile', 0);
      $coolServer = $htmlObject1->find('div#servercool', 0);
      $videoData['hdIframe'] = '';
      $videoData['standardIframe'] = '';
      $videoData['coolIframe'] = '';
      $videoData['mp4Iframe'] = '';
      $videoData['server1Iframe'] = '';
      if ($hdServer) {//hd 720
        $playerLink = $hdServer->find('iframe', 0)->src;
        $videoData['hdIframe'] = $playerLink;
      }
      if ($mobileServer) {//default
        $playerLink = $mobileServer->find('iframe', 0)->src;
        $videoData['standardIframe'] = $playerLink;
      }
      preg_match_all('#<script(.*?)</script>#is', $contentUrl, $matches);
      if ($coolServer) {
        if($coolServer->find('iframe', 0)){
          $playerLink = $coolServer->find('iframe', 0)->src;
          $videoData['coolIframe'] = $playerLink;
        }else{
          $iframeScript = $matches[0][9];
          $coolIframeString = getStringBetween($iframeScript, 'link: "', '"', false);
          $videoData['coolIframe'] = $coolIframeString;
          $coolStreamingUrl = getStringBetween($iframeScript, "{file: '", "',", false);
          $videoData['coolStreamingUrl'] = base64_encode($coolStreamingUrl);
        }

      }

      $iframeScript = $matches[0][13];
      $mp4IframeString = getStringBetween($iframeScript, "src='", "' ", false);
      $videoData['mp4Iframe'] = $mp4IframeString;

      //server 1
      $iframeScriptServer1 = $matches[0][11];
      $server1IframeString = getStringBetween($iframeScriptServer1, "src='", "' ", false);
      if(empty($server1IframeString)){
        $iframeScriptServer1 = $matches[0][10];
        $server1IframeString = getStringBetween($iframeScriptServer1, "src='", "' ", false);
      }
      $videoData['server1Iframe'] = $server1IframeString;
    }
    return $videoData;
  }

  //server = (hd, cool, standard, mp4)
  public function getVideoSourceFromIframe($iframePlayerLink, $server = 'hd') {
    $strSources = '';
    switch ($server) {
      case 'hd':
        $strSources = str_replace('http://www.dramacool.com/embeddramanox.php?id=','',$iframePlayerLink);
        //$strSources = $htmlAll->find('source', 0)->src;
        break;
      case 'standard'://mobile
      case 'mobile':
        if(strpos($iframePlayerLink, 'embeddramanox')){
          $strSources = str_replace('http://www.dramacool.com/embeddramanox.php?id=','',$iframePlayerLink);
          $strSources = base64_encode($strSources);
        }elseif(strpos($iframePlayerLink, 'videoupload.us')){
          $playerSource = getFileContent($iframePlayerLink);
          $strSources = getStringBetween($playerSource, "file: '", "',", false);
          if($strSources){
            $strSources = base64_encode($strSources);
          }
        }
        break;
      case 'cool':
        //$strSources = getStringBetween($playerSource, "file: '", "',", false);
        //$strSources = base64_encode($strSources);
        $strSources = '';
        break;
      case 'mp4':
        /*$strSources = getStringBetween($playerSource, "clip:", "?start=0", false);
        $strSources = preg_replace('/\s+/', '', $strSources);
        $strSources = str_replace("{url:'", '', $strSources);
        $strSources = base64_encode($strSources);
        */
        $strSources = '';
        break;
      case 'server1':
        //$strSources = getStringBetween($playerSource, "file: '", "',", false);
        //$strSources = base64_encode($strSources);
        $strSources = '';
        break;
      default:
        break;
    }
    return $strSources;
  }

  public function getSeriesData($url) {
    $data = array();
    $contentUrl = getFileContent($url);
    if (!$contentUrl) {
      return false;
    }
    $htmlAll = str_get_html($contentUrl);
    if ($htmlAll) {
      $metaArr = getMetaFromHtml($contentUrl);
      $seriesThumb = $metaArr['og:image'];
      $seriesTitle = trimtext($htmlAll->find('div.title-detail-film', 0)->plaintext);
      $seriesDesc = trimtext(str_replace("Description", '', $htmlAll->find('div.desc-detail-film', 0)->plaintext));
      $seriesCountry = trimtext(str_replace("Country:", '', $htmlAll->find('div.country-detail-film', 0)->plaintext));
      $seriesStatus = trimtext(str_replace("Status:", '', $htmlAll->find('div.status-detail-film', 0)->plaintext));
      $seriesRelease = trimtext(str_replace("Released:", '', $htmlAll->find('div.released-detail-film', 0)->plaintext));
      $seriesGenre = trimtext(str_replace("Genre:", '', $htmlAll->find('div.genre-detail-film', 0)->plaintext));

      $genre = array();
      $genreDb = $this->Genre_model->getAll();
      foreach ($genreDb as $gId => $gName) {
        if (strpos($seriesGenre, $gName) !== FALSE) {
          $genre[$gId] = $gName;
        }
      }

      $data['title'] = $seriesTitle;
      $data['description'] = $seriesDesc;
      $data['country'] = isset($this->_config['flip_countries'][$seriesCountry]) ? $this->_config['flip_countries'][$seriesCountry] : 0;
      $data['release_date'] = $seriesRelease;
      $data['is_complete'] = $seriesStatus == 'Completed' ? 1 : 0;
      $data['genre'] = $genre;
      $data['thumbnail'] = str_replace(' ', '%20', $seriesThumb);
      $data['original_url'] = $url;

      $episodeArr = array();
      $episodeBlocks = $htmlAll->find('div.view-detail', 0)->find('div.row-9dr');
      if ($episodeBlocks) {
        foreach ($episodeBlocks as $epBlock) {
          $videoData = array();
          $tmpTitle = trimtext($epBlock->find('div.title_s_az', 0)->plaintext);
          $time = trimtext($epBlock->find('span.timeago', 0)->plaintext);
          $tmpTitle = trimtext(str_replace($time, '', $tmpTitle));
          $subStatus = strtolower(trimtext($epBlock->find('div.country_s_az', 0)->plaintext));
          $subStatus = $subStatus == 'sub' ? 1 : 0;
          $itemLink = $epBlock->find('a', 0)->href;
          $publishDate = format2DateTime($time);
          $videoData['publish_date'] = $publishDate;
          $videoData['has_sub'] = $subStatus;
          $videoData['original_url'] = $itemLink;
          $videoData['title'] = $tmpTitle;
          $videoData['status'] = STATUS_WAIT_FOR_APPROVE;
          $videoData['episode'] = $this->_getLastNumberInString($tmpTitle);
          $episodeArr[] = $videoData;
        }
      }
      $data['videos'] = $episodeArr;
    }
    return $data;
  }

  public function importGenre() {
    $url = base_url() . UPLOAD_PATH . 'genre.html';
    $contentUrl = getFileContent($url);
    $htmlAll = str_get_html($contentUrl);
    $options = $htmlAll->find('option');
    foreach ($options as $option) {
      $genreName = $option->plaintext;
      $dbGenre = $this->Genre_model->getByName($genreName);
      if (!$dbGenre) {
        $data['name'] = $genreName;
        $this->Genre_model->insert($data);
      }
    }
  }

  private function _getLastNumberInString($text) {
    if (preg_match_all('/\d+/', $text, $numbers))
      $lastnum = end($numbers[0]);
    return $lastnum;
  }
  public function getMovieSeriesLink($url){
    $data = array();
    $contentUrl = getFileContent($url);
    if (!$contentUrl) {
      return false;
    }
    $htmlAll = str_get_html($contentUrl);
    if ($htmlAll) {
      $dramalistObj = $htmlAll->find('div.drama-list', 0);
      if($dramalistObj){
        $linkObjs = $dramalistObj->find('div.listdramacool');
        foreach($linkObjs as $linkObj){
          $link = $linkObj->find('a', 0)->href;
          $data[] = DRAMA_COOL_SITE_URL.$link;
        }
      }
        
    }
    return $data;
  }
  public function getAllSeriesLinkByChar($type) {
    $alphas = range('A', 'Z');
    $alphas[] = 'other';
    $linkArr = array();
    $urlPattern = DRAMA_LIST_BY_CHAR_URL_PATTERN;
    if($type == VIDEO_TYPE_SHOW){
      $urlPattern = SHOW_LIST_BY_CHAR_URL_PATTERN;
    }
    foreach ($alphas as $alpha) {
      $url = sprintf($urlPattern, $alpha);
      $contentUrl = getFileContent($url);
      $htmlAll = str_get_html($contentUrl);
      $pagingObj = $htmlAll->find('ul.pagination-list', 0);
      if ($pagingObj) {
        $lastPageUrl = $pagingObj->find('li.last', 0)->find('a', 0)->href;
        $lastPage = $this->_getLastNumberInString($lastPageUrl);
        $pagePrefix = str_replace('/' . $lastPage, '/', $lastPageUrl);
        for ($page = 1; $page <= $lastPage; $page++) {
          $link = DRAMA_COOL_SITE_URL . $pagePrefix . $page;
          $seriesLinkByChar = $this->getSeriesLinkByChar($link);
          $linkArr = array_merge($linkArr, $seriesLinkByChar);
        }
      } else {
        $seriesLinkByChar = $this->getSeriesLinkByChar($url);
        $linkArr = array_merge($linkArr, $seriesLinkByChar);
      }
    }
    return $linkArr;
  }

  //1 chu co nhieu series
  public function getSeriesLinkByChar($url) {
    $contentUrl = getFileContent($url);
    $htmlAll = str_get_html($contentUrl);
    $ret = array();
    $containerObj = $htmlAll->find('div#view-large', 0);
    if($containerObj){
      $seriesLinkObjs = $containerObj->find('div.thumbnail-body-item');
      if ($seriesLinkObjs) {
        foreach ($seriesLinkObjs as $seriesLinkObj) {
          $link = $seriesLinkObj->find('a', 0)->href;
          $ret[] = $link;
          //echo "\n$link\n";
        }
      }
    }
    return $ret;
  }

}
