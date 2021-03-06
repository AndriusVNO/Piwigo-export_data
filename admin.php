<?php
if (!defined("PHPWG_ROOT_PATH"))
{
  die ("Hacking attempt!");
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(ACCESS_ADMINISTRATOR);

load_language('plugin.lang', EXPORT_DATA_PATH);

// +-----------------------------------------------------------------------+
// | Tabs                                                                  |
// +-----------------------------------------------------------------------+

$page['tab'] = 'home';

// tabsheet
include_once(PHPWG_ROOT_PATH.'admin/include/tabsheet.class.php');
$tabsheet = new tabsheet();
$tabsheet->set_id('export_data');

$tabsheet->add('home', l10n('Export Data'), EXPORT_DATA_ADMIN);
$tabsheet->select($page['tab']);
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// | Actions                                                               |
// +-----------------------------------------------------------------------+

if (isset($_GET['type']))
{
  // output headers so that the file is downloaded rather than displayed
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=piwigo-albums.csv');
  
  // create a file pointer connected to the output stream
  $output = fopen('php://output', 'w');
  fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

  // create URL list of albums
  if ('albums' == $_GET['type'])
  {
    $query = '
SELECT
    id,
    name,
    permalink
  FROM '.CATEGORIES_TABLE.'
  ORDER BY id ASC
;';
    $result = pwg_query($query);
    set_make_full_url();
    while ($row = pwg_db_fetch_assoc($result))
    {
      fputcsv($output, array('url' => make_index_url(array('category' => $row))));
    }
  }

  // create list of albums with details
  if ('albums1' == $_GET['type'])
  {
    $query = '
SELECT
    id,
    name,
    comment,
    dir,
    uppercats,
    lastmodified
  FROM '.CATEGORIES_TABLE.'
  ORDER BY id ASC
;';
    $result = pwg_query($query);
    $is_first = true;
    while ($row = pwg_db_fetch_assoc($result))
    {
      if ($is_first)
      {
        fputcsv($output, array_keys($row));
        $is_first = false;
      }
      fputcsv($output, $row);
    }
  }

  // create list of photos with details
  if ('photos' == $_GET['type'])
  {
    $query = '
SELECT
    i.id,
    file,
    date_available,
    date_creation,
    i.name AS title,
    author,
    hit,
    filesize,
    width,
    height,
    latitude,
    longitude,
    group_concat(t.name) AS tags,
    comment AS description
  FROM '.IMAGES_TABLE.' AS i
    LEFT JOIN '.IMAGE_TAG_TABLE.' ON image_id=i.id
    LEFT JOIN '.TAGS_TABLE.' AS t ON tag_id=t.id
  GROUP BY i.id
  ORDER BY i.id
;';
    $result = pwg_query($query);
    $is_first = true;
    while ($row = pwg_db_fetch_assoc($result))
    {
      if ($is_first)
      {
        fputcsv($output, array_keys($row));
        $is_first = false;
      }
        fputcsv($output, $row);
    }
  }

  // create list of comments
  if ('comments' == $_GET['type'])
  {
    $query = '
SELECT
    id,
    image_id,
    date,
    author,
    email,
    author_id,
    anonymous_id,
    website_url,
    validated,
    validation_date,
    content
  FROM '.COMMENTS_TABLE.'
  ORDER BY id
;';
    $result = pwg_query($query);
    $is_first = true;
    while ($row = pwg_db_fetch_assoc($result))
    {
      if ($is_first)
      {
        fputcsv($output, array_keys($row));
        $is_first = false;
      }
      fputcsv($output, $row);
    }
  }

  // create info about downloads
  if ('downloads' == $_GET['type'])
  {
    $query = '
SELECT
    id,
    date,
    time,
    user_id,
    IP,
    image_id
  FROM '.HISTORY_TABLE.'
  WHERE image_type = \'high\'
  ORDER BY id DESC
;';
    $history_lines = query2array($query);
    if (count($history_lines) == 0)
    {
      exit();
    }

    // we now need the list of image_ids (to find filename, title, related albums) and list of user_ids (to find their name)
    $image_ids = array();
    $user_ids = array();
    foreach ($history_lines as $history_line)
    {
      @$image_ids[ $history_line['image_id'] ]++;
      @$user_ids[ $history_line['user_id'] ]++;
    }

    // get an album (or none) for each photo
    $query = '
SELECT
    image_id,
    category_id
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE image_id IN ('.implode(',', array_keys($image_ids)).')
;';
    $category_id_of_image = query2array($query, 'image_id', 'category_id');
    $category_path_of = array();
    $category_ids = array();
    foreach ($category_id_of_image as $image_id => $category_id)
    {
      @$category_ids[$category_id]++;
    }
    $cat_max_level = 0;
    if (count($category_ids) > 0)
    {
      // we're going to need 2 SQL queries: first one to get the list of uppercats,
      // second one to list id,name,permalink of these uppercats
      $query = '
SELECT id,uppercats
  FROM '.CATEGORIES_TABLE.'
  WHERE id IN ('.implode(',', array_keys($category_ids)).')
;';
      $uppercats_of = query2array($query, 'id', 'uppercats');
      $all_cat_ids = array();
      foreach ($uppercats_of as $uppercats)
      {
        $all_cat_ids = array_merge($all_cat_ids, explode(',', $uppercats));
      }
      $all_cat_ids = array_unique($all_cat_ids);
      $query = '
SELECT
    id,
    name,
    permalink
  FROM '.CATEGORIES_TABLE.'
  WHERE id IN ('.implode(',', $all_cat_ids).')
;';
      $cat_map = query2array($query, 'id');
      foreach ($uppercats_of as $id => $uppercats)
      {
        $cats = array();
        foreach (explode(',', $uppercats) as $id)
        {
          $cats[] = $cat_map[$id];
        }
        if (count($cats) > $cat_max_level)
        {
          $cat_max_level = count($cats);
        }
        $conf['level_separator'] = '~#~'; // in case an album name would contain a "/"
        $category_path_of[$id] = explode($conf['level_separator'], get_cat_display_name($cats, null));
      }
    }
    // echo '<pre>'; print_r($category_path_of); echo '</pre>'; exit();

    // info about images (filename, title)
    $query = '
SELECT
    id,
    file,
    name
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.implode(',', array_keys($image_ids)).')
;';
    $image_infos_of = query2array($query, 'id');

    // info about users
    $query = '
SELECT
    '.$conf['user_fields']['id'].' AS id,
    '.$conf['user_fields']['email'].' AS email,
    '.$conf['user_fields']['username'].' AS username
  FROM '.USERS_TABLE.'
  WHERE '.$conf['user_fields']['id'].' IN ('.implode(',', array_keys($user_ids)).')
;';
    $user_infos_of = query2array($query, 'id');

    $is_first = true;
    foreach ($history_lines as $history_line)
    {
      if ($is_first)
      {
        $row = array(
          '#history',
          'datetime',
          'user id',
          'user name',
          'user email',
          'user IP',
          'photo id',
          'photo filename',
          'photo title',
        );

        if ($cat_max_level > 0)
        {
          for ($i = 1; $i <= $cat_max_level; $i++)
          {
            $row[] = 'album level '.$i;
          }
        }
        fputcsv($output, $row);
        // echo '<pre>'; print_r($row); echo '</pre>';
        $is_first = false;
      }
      
      $row = array(
        $history_line['id'],
        $history_line['date'].' '.$history_line['time'],
        $history_line['user_id'],
        $user_infos_of[ $history_line['user_id'] ]['username'],
        $user_infos_of[ $history_line['user_id'] ]['email'],
        $history_line['IP'],
        $history_line['image_id'],
        isset($image_infos_of[ $history_line['image_id'] ]) ? $image_infos_of[ $history_line['image_id'] ]['file'] : 'file no longer exists',
        isset($image_infos_of[ $history_line['image_id'] ]) ? $image_infos_of[ $history_line['image_id'] ]['name'] : '',
      );

      if (isset($category_id_of_image[ $history_line['image_id'] ]))
      {
        $row = array_merge(
          $row,
          $category_path_of[ $category_id_of_image[ $history_line['image_id'] ] ]
        );
      }

      fputcsv($output, $row);
      // echo '<pre>'; print_r($row); echo '</pre>';
    }
  }

  exit();
}

// +-----------------------------------------------------------------------+
// | form                                                                  |
// +-----------------------------------------------------------------------+

// define template file
$template->set_filename('export_data_content', realpath(EXPORT_DATA_PATH . 'admin.tpl'));

// template vars
$template->assign('EXPORT_DATA_ADMIN', EXPORT_DATA_ADMIN);

// +-----------------------------------------------------------------------+
// | sending html code                                                     |
// +-----------------------------------------------------------------------+

// send page content
$template->assign_var_from_handle('ADMIN_CONTENT', 'export_data_content');

?>
