<?php
  if (!isset($_GET["debug"])) {
    header("Content-Type: audio/x-mpegurl");
    header("Content-Disposition: attachment; filename=iptv-org.m3u");
  }

  //configuration parameters
  $countries = isset($_GET["country"]) ? str_getcsv($_GET['country']) : ["us","uk","ca","au","nz"];
  $quality   = isset($_GET["quality"]) ? str_getcsv($_GET["quality"]) : ["0","240","480","720","1080","2160","4320"];
  $nsfw      = isset($_GET["nsfw"]) ? $_GET["nsfw"] : 0;
  $debug     = isset($_GET["debug"]) ? $_GET["debug"] : 0;
  $include   = isset($_GET["include"]) ? $_GET["include"] : null;

  //get a list of all available online streams
  $streams_api     = file_get_contents('https://iptv-org.github.io/api/streams.json');
  $channels        = json_decode($streams_api);
  $online_channels = array();

  //for each available online stream, if the stream is in the list of specified countries, store it in a list
  foreach ($channels as $channel) {
    if ($channel->status == "online" && in_array(substr($channel->channel, -2), $countries) && property_exists($channel, "height") && in_array($channel->height, $quality))
      $online_channels[$channel->channel] = $channel;
  }

  //get a list of all channel information
  $channels_api = file_get_contents('https://iptv-org.github.io/api/channels.json');
  $channels     = json_decode($channels_api);

  //match the channel information to the stored list of available online streams and merge it
  foreach ($channels as $channel) {
    if ($channel->is_nsfw == $nsfw && array_key_exists($channel->id, $online_channels)) {
      $online_channels[$channel->id] = (object) array_merge((array) $channel, (array) $online_channels[$channel->id]);
      $online_channels[$channel->id]->stream_url = $online_channels[$channel->id]->url;
      unset($online_channels[$channel->id]->url);
    }
  }

  //get a list of all tv guides
  $guides_api = file_get_contents('https://iptv-org.github.io/api/guides.json');
  $guides     = json_decode($guides_api);

  //match the tv guide to the stored list of available online streams and merge it
  foreach ($guides as $guide) {
    if (array_key_exists($guide->channel, $online_channels)) {
      $online_channels[$guide->channel] = (object) array_merge((array) $online_channels[$guide->channel], (array) $guide);
      $online_channels[$guide->channel]->guide_url = $guide->url;
      unset($online_channels[$guide->channel]->url);
    }
  }

  $tvg_urls = array();

  //get all unique tv guides from the stored list of available online streams and store it in another list
  foreach ($online_channels as $channel) {
    if(property_exists($channel, "guide_url") && !in_array($channel->guide_url, $tvg_urls))
      $tvg_urls[] = $channel->guide_url;
  }

  if ($include !== null) {
    $m3u = file_get_contents($include);
    $re = '/#EXTINF:(.+?)[,]\s?(.+?)[\r\n]+?((?:https?|rtmp):\/\/(?:\S*?\.\S*?)(?:[\s)\[\]{};"\'<]|\.\s|$))/';
    $attributes = '/([a-zA-Z0-9\-\_]+?)="([^"]*)"/';

    $m3u = str_replace('tvg-logo', 'logo', $m3u);
    $m3u = str_replace('tvg-id', 'id', $m3u);
    $m3u = str_replace('tvg-name', 'author', $m3u);
    $m3u = str_replace('group-title', 'group', $m3u);
    $m3u = str_replace('tvg-country', 'country', $m3u);
    $m3u = str_replace('tvg-language', 'language', $m3u);

    preg_match_all($re, $m3u, $matches);

    $items = array();

    foreach($matches[0] as $list) {
      preg_match($re, $list, $matchList);
      $mediaURL = preg_replace("/[\n\r]/","",$matchList[3]);
      $mediaURL = preg_replace('/\s+/', '', $mediaURL);

      $newdata =  array(
        'name' => $matchList[2],
        'stream_url' => $mediaURL
      );

      preg_match_all($attributes, $list, $matches, PREG_SET_ORDER);

      foreach ($matches as $match) {
        if ($match[1] == "group")
          $newdata["categories"] = array($match[2]);

        $newdata[$match[1]] = $match[2];
      }

      $items[$matches[0][2]][] = (object) $newdata[0];
    }
  }

  if ($debug == true)
    die("<pre>" . print_r($items, true) . "</pre>");
    // die("<pre>" . print_r($online_channels, true) . "</pre>");
?>#EXTM3U url-tvg="<?php echo implode(",", $tvg_urls); ?>"
<?php foreach ($online_channels as $channel): ?>
#EXTINF:-1 tvg-id="<?php echo $channel->id; ?>" tvg-name="<?php echo $channel->name; ?>" tvg-logo="<?php echo $channel->logo; ?>" group-title="<?php echo (property_exists($channel, "categories") && !empty($channel->categories)) ? ucfirst($channel->categories[0]) : "Uncategorized"; ?>",<?php echo $channel->name . "\n"; ?>
<?php echo $channel->stream_url . "\n"; ?>
<?php endforeach ?>