<?php

namespace Uploader;

use lib\PException;
use lib\Session;
use lib\MysqlAdapter;
use lib\Utilities;
use lib\AdminSession;
use lib\FTP;
use models\Foto;
use models\Captcha;

class Uploader
{
    // реально поддерживаются только эти форматы
    public static $_picture_ext = array('jpg', 'gif', 'png', 'jpeg');
    public static $_audio_ext = array('ape', 'flac', 'm4a', 'mdi', 'ram', 'mp3', 'ogg', 'wma', 'wav');
    public static $_files_ext = array('gp3', 'jtd', 'lwp', 'ods', 'odt', 'pdf', 'pps', 'ppsx', 'ppt', 'sxw', 'vsd', 'wpd', 'wpd', 'wpd', 'wps', 'xls', '7z', '7zip', 'bin', 'cbr', 'cbz', 'jar', 'r00', 'rar', 'zip', 'zix', 'docx', 'dotx', 'ppsx', 'pptm', 'pptx', 'xlsm', 'xlsx', 'txt', 'rtf', 'vob', 'iso', 'cue', 'doc');
    public static $_video_ext = array('3gp', 'avi', 'flv', 'm4v', 'mkv', 'mov', 'mp4', 'rm', 'wmv', 'mpeg', 'mpg');
    public static $_upload_tmp = '/tmp/';

    protected static $_storage_base = '/root/domains/web/upload';
    protected static $_types_user = array('audio', 'files', 'picture', 'video');

    /**
     * внешняя функция для загрузки через пульт
     *
     * @param $files
     * @param int $admin
     * @return int
     */
    public static function pultUploadFiles(&$files, $admin = 0)
    {
        if (empty($files)) {
            return 0;
        }

        // определение типа загрузки (юзерская, анонимная, админская)
        if (!$admin) {
            $session = Session::getInstance();
            if ($session->isActiveUser()) {
                $upload_type = 'users';
                $user_id = $session->getUserId();
            } else {
                if (isset($_POST['field1_file1']) && Captcha::validateCaptcha($_POST['field1_file1'], $_POST['field2_file1'])) {
                    $upload_type = 'anon';
                    $user_id = 0;
                } else {
                    // неверно введена капча
                    return -1;
                }
            }
        } else {
            if (AdminSession::getInstance()->check()) {
                $upload_type = 'admins';
                $user_id = AdminSession::getInstance()->getUserId();
            } else {
                // админ не залогинен
                return -1;
            }
        }

        $cnt = count($files);
        $cnt_real = 0;

        for ($i = 0; $i < $cnt; $i++) {
            $files[$i]['done'] = 0;
            if (empty($files[$i]['path'])) {
                continue;
            }

            try {
                $new_path = self::makeFileData($files[$i], $upload_type, $user_id);
            } catch (PException $e) {
                error_log('[Uploader] ' . $e);
                continue;
            }

            if (empty($new_path)) {
                continue;
            }

            $new_tmp_abs_path = self::$_upload_tmp . $files[$i]['new_short_name'];
            rename($files[$i]['tmp_abs_path'], $new_tmp_abs_path);
            chmod($new_tmp_abs_path, 0644);

            if (in_array($files[$i]['transform_file'], array('in', 'crop'), true)) {
                Foto::resizeFoto($new_tmp_abs_path, null, $files[$i]['transform_file'], $files[$i]['max_wi_file'], $files[$i]['max_he_file'], -80, $files[$i]['not_max']);
            }

            if (!self::moveUploadFileFtp($new_tmp_abs_path, $files[$i]['new_url_path'])) {
                continue;
            }

            // для не картинок по дефолту ставим нулевые размеры
            $files[$i]['width'] = 0;
            $files[$i]['height'] = 0;

            // если картинка то делаем еще и превьюшку с префиксом к имени th_
            // и вычисляем фактические размеры после преобразования (если оно было)
            if ($files[$i]['type'] == 'picture') {
                if (!empty($new_tmp_abs_path)) {
                    $th_abs_path = self::$_upload_tmp . 'th_' . $files[$i]['new_short_name'];

                    $files[$i]['th_max_wi_file'] = isset($files[$i]['max_wi_file']) ? $files[$i]['max_wi_file'] : 100;
                    $files[$i]['th_max_he_file'] = isset($files[$i]['th_max_he_file']) ? $files[$i]['th_max_he_file'] : 100;

                    if (Foto::resizeFoto($new_tmp_abs_path, $th_abs_path, 'in', $files[$i]['th_max_wi_file'], $files[$i]['th_max_he_file'], -80, 1)) {
                        $th_relative_path = str_replace($files[$i]['new_short_name'], 'th_' . $files[$i]['new_short_name'], $files[$i]['new_url_path']);
                        self::moveUploadFileFtp($th_abs_path, $th_relative_path);
                    } else {
                        error_log('[Uploader] Can not resize image: ' . $new_tmp_abs_path);
                    }

                    // размеры
                    $list_hw = getimagesize($new_tmp_abs_path);
                    if ($list_hw !== false) {
                        list($files[$i]['width'], $files[$i]['height']) = $list_hw;
                    }
                }
            }

            $db = MysqlAdapter::getInstance('pb');
            $sql =
                'INSERT INTO `upload_files` SET
                  `original_filename` = ?,
                  `filetype` = ?,
                  `fileext` = ?,
                  `filesize` = ?,
                  `user_filename` = ?,
                  `file_ann` = ?,
                  `user_id` = ?,
                  `user_ip` = ?,
                  `dtime` = NOW(),
                  `tags` = ?,
                  `status` = ?,
                  `priv` = ?,
                  `owner` = "1",
                  `source_name` = ?,
                  `source_link` = ?,
                  `uploadtype` = ?
               ';
            $status = $files[$i]['type'] == 'video' ? 'no' : 'yes';
            $private = $files[$i]['private'] ? 'yes' : 'no';
            $bind = array(
                $files[$i]['new_short_name'],
                $files[$i]['type'],
                $files[$i]['ext'],
                $files[$i]['size'],
                htmlspecialchars($files[$i]['title']),
                //htmlspecialchars($files[$i]['desc']),
                nl2br(strip_tags($files[$i]['desc'], '<a>')),
                $user_id,
                $_SERVER['REMOTE_ADDR'],
                htmlspecialchars($files[$i]['tags']),
                $status,
                $private,
                htmlspecialchars($files[$i]['source_name']),
                htmlspecialchars($files[$i]['source_url']),
                $files[$i]['uploadtype'],
            );
            $db->query($sql, $bind);
            $last_inserted_id = mysql_insert_id($db->getHandler());

            $files[$i]['file_id'] = $last_inserted_id;
            $files[$i]['done'] = 1;
            $cnt_real++;

            $exp_tags = explode(',', $files[$i]['tags']);
            for ($j = 0, $cnt2 = count($exp_tags); $j < $cnt2; $j++) {
                /* NEW Tag tables */
                $clear_tag = (string) Utilities::clearTagHard($exp_tags[$j]);
                if ($clear_tag !== '') {
                    $clear_tag_id = $db->fetchOne('SELECT `tag_id` FROM `upload_new_tags` WHERE `tag` = ? ', array($clear_tag));

                    if (empty($clear_tag_id)) {
                        // тега еще нет - вставляем
                        $db->query('INSERT INTO `upload_new_tags` SET `tag` = ?', array($clear_tag));
                        $clear_tag_id = mysql_insert_id($db->getHandler());
                    } else {
                        // тег есть - увеличиваем количество упоминаний
                        $db->query('UPDATE `upload_new_tags` SET `count` = `count`+1 WHERE `tag_id` = ? ', array($clear_tag_id));
                    }

                    $db->query('INSERT INTO `upload_new_tags_files` SET `tag_id` = ?, `file_id` = ?', array($clear_tag_id, $last_inserted_id));
                }
            }

            if ($files[$i]['uploadtype'] == 'admins') {
                AdminSession::getInstance()->_log(
                    "Загружен файл {$files[$i]['new_full_url']} ( id = {$files[$i]['file_id']} )"
                );
                Debug::logUserAction("uploaded file id={$files[$i]['file_id']} and file_url={$files[$i]['new_url_path']} from admin_id={$user_id}");
            } else {
                Debug::logUserAction("uploaded file id={$files[$i]['file_id']} and file_url={$files[$i]['new_url_path']} from member_id={$user_id}");
            }
        }
        return $cnt_real;
    }

    /**
     *  функция анализирует загруженный файл, решает можно ли его залить в аплоад на фтп.
     *  достает продробную информацию о файле
     *  формирует новый путь для файла - путь для файлового хранилища
     *  функция служебная - необходима для загрузки файла по фтп на сервак с хранилищем
     *
     *  $upload_type - тип загрузки ( anon | users | admins )
     *
     *  в случае если переданы некорректные данные возвращает пустую строку - признак ошибки
     *
     *  $file -
     *     массив с данными о файле, передается по ссылке, часть данных должны быть определена в вызывающей функции
     *     подробная структура массива в первых строчках функции.
     *
     *  только эти 2 параметра обязательные:
     *     $file['name'] - имя файла на машине пользователя
     *     $file['path'] - имя файла в тмп директории
     *
     *  $owner_id: если $type=users, определяет user_id юзера что загрузил файл
     *             если $type=admins, определяет admin_id что загрузил файл может быть пустым - тогда файл грузится в корень админского аплоада
     *             если $type=anon, не важно
     *
     * & array (
            'name' => '__test2.jpg',
            'path' => '0000023105',
            'desc' => '',
            'title' => '',
            'tags' => '',
            'source_name' => '',
            'source_url' => '',
            'private' => 0,
            'done' => 0,
            'tmp_abs_path' => '/tmp/0000023105',
            'size' => 13401,
            'new_short_name' => 'e62e435fcf371e48eaf721519d3bbdec.jpg',
            'ext' => 'jpg',
            'new_path' => '/root/domains/web/upload/anon/picture/e62e435fcf371e48eaf721519d3bbdec.jpg',
            'new_url_path' => 'upload/anon/picture/e62e435fcf371e48eaf721519d3bbdec.jpg',
            'new_full_url' => 'http://domain.ru/upload/anon/picture/e62e435fcf371e48eaf721519d3bbdec.jpg',
            'new_path_dir' => '/root/domains/web/upload/anon/picture/',
            'new_path_dir_relative' => 'picture',
            'type' => 'picture',
        )
     *
     * @param array $file
     * @param string $upload_type
     * @param int $owner_id
     * @return string
     */
    public static function makeFileData(&$file = array(), $upload_type = 'anon', $owner_id = 0)
    {
        // файл не может быть обработан
        if ($file['path'] == '') {
            throw new PException("Uploader: tmp is empty");
        }

        if ($file['name'] == '') {
            throw new PException("Uploader: user file name is empty, tmp={$file['path']}");
        }

        $exp0 = explode('/', $file['path']);  // защита от абсолютного пути. отрезаем все кроме того что после последнего слеша
        $file['path'] = $exp0[count($exp0) - 1]; // относительный tmp путь
        $file['path'] = str_replace(array('~'), array(''), $file['path']);
        if ($file['path'] == '') {
            throw new PException("Uploader: tmp is empty"); // пустое имя файла
        }

        $file['tmp_abs_path'] = self::$_upload_tmp . $file['path']; // абсолютный tmp путь
        if (!file_exists($file['tmp_abs_path'])) {
            throw new PException("Uploader: file not exists, tmp={$file['path']}"); // файл не существует
        }

        $file['size'] = (int) filesize($file['tmp_abs_path']);
        if ($file['size'] > 104857600) {
            throw new PException("Uploader: file more than 100m, tmp={$file['path']}"); // файл больше 100 метров
        }

        $file['new_short_name'] = ''; // имя файла после переимнования (без пути)
        $file['ext'] = '';            // расширение файла
        $file['new_path'] = '';       // новый путь к файлу (дирерктория + имя + расширение)
        $file['new_url_path'] = '';   // урл файла относительно корня сайта - так же используется как относительный урл для загрузке по фтп
        $file['new_full_url'] = '';   // полный урл файла: http://domain.ru/upload/admins/picture/e719661cbbe572e30c2fc63a2ee1948a.jpg
        $file['new_path_dir'] = '';   // путь к новой дирреткории файла
        $file['new_path_dir_relative'] = ''; // новая относительная дирректория, переменная нужна для создания подпапок.

        $file['name'] = urldecode($file['name']); // обязательное имя файла на машине пользовтаеля
        $file['desc'] = !empty($file['desc']) ? urldecode($file['desc']) : ''; // описание от пользователя
        $file['title'] = !empty($file['title']) ? urldecode($file['title']) : '';  // заголовок данный пользователем
        $file['tags'] = !empty($file['tags']) ? urldecode($file['tags']) : '';  // теги данные пользователем
        $file['source_name'] = !empty($file['source_name']) ? urldecode($file['source_name']) : '';  // название источника данное пользователем
        $file['source_url'] = !empty($file['source_url']) ? urldecode($file['source_url']) : '';  // урл источника данный пользователем

        $ext = strrpos($file['name'], '.');
        if ($ext === false) {
            throw new PException("Uploader: empty ext, tmp={$file['path']}"); // не определено расширение
        }

        $ext = mb_strtolower(substr($file['name'], $ext + 1));
        if (empty($ext)) {
            throw new PException("Uploader: empty ext, tmp={$file['path']}"); // пустое расширение
        }

        $file['ext'] = $ext;
        $file['new_short_name'] = md5(microtime() . getmypid()) . '.' . $ext;

        switch (true) {
            case (in_array($file['ext'], self::$_video_ext, true)):
                $file['type'] = 'video';
                break;
            case (in_array($file['ext'], self::$_audio_ext, true)):
                $file['type'] = 'audio';
                break;
            case (in_array($file['ext'], self::$_picture_ext, true)):
                $file['type'] = 'picture';
                break;
            case (in_array($file['ext'], self::$_files_ext, true)):
                $file['type'] = 'files';
                break;
            // админы могут грузить флеш
            case ($upload_type == 'admins' && $file['ext'] = 'swf'):
                $file['type'] = 'files';
                break;
            default:
                throw new PException("Uploader: unauthorized file type, tmp={$file['path']}, ext={$file['ext']}");
        }

        switch ($upload_type) {
            case 'users':
                if (empty($owner_id)) {
                    throw new PException("Uploader: undefined user, tmp={$file['path']}"); // не определен юзер
                }
                $tmp_path = '/users/' . $file['type'] . '/' . $owner_id . '/';
                $file['new_path_dir_relative'] = $file['type'] . '/' . $owner_id;
                $file['uploadtype'] = 'users';
                break;
            case 'admins':
                if (empty($owner_id)) {
                    $tmp_path = '/admins/' . $file['type'] . '/';
                    $file['new_path_dir_relative'] = $file['type'];
                } else {
                    $tmp_path = '/admins/' . $file['type'] . '/' . $owner_id . '/';
                    $file['new_path_dir_relative'] = $file['type'] . '/' . $owner_id;
                }
                $file['uploadtype'] = 'admins';
                break;
            // anon
            default:
                $tmp_path = '/anon/' . $file['type'] . '/';
                $file['new_path_dir_relative'] = $file['type'];
                $file['uploadtype'] = 'anon';
        }

        $file['new_path_dir'] = self::$_storage_base . $tmp_path;
        $file['new_path'] = $file['new_path_dir'] . $file['new_short_name'];
        $file['new_url_path'] = 'upload' . $tmp_path . $file['new_short_name'];
        $file['new_full_url'] = UPLOAD_URL . substr($tmp_path, 1) . $file['new_short_name'];

        return $file['new_path'];
    }

    /**
     * перемещение файла на фтп
     *
     * $tmp_name - абсолютный путь на машине где лежит перемещаемый файл
     * $target_name_relative - относительный путь на машине-хранилище, отсчитывается от корня фтп дирректории
     * пример $target_name_relative = $new_url_path из функции makeFileData() = 'upload/admins/picture/e719661cbbe572e30c2fc63a2ee1948a.jpg'
     *
     * @param $tmp_name
     * @param $target_name_relative
     * @return bool
     */
    public static function moveUploadFileFtp($tmp_name, $target_name_relative)
    {
        if (!file_exists($tmp_name)) {
            return false;
        }
        if (empty($target_name_relative)) {
            return false;
        }

        $FTP_CONF = FTP::getSettings();
        $ftp_server = $FTP_CONF['newupload']['host'];
        $ftp_user_name = $FTP_CONF['newupload']['user'];
        $ftp_user_pass = $FTP_CONF['newupload']['password'];

        $conn_id = ftp_connect($ftp_server);
        $login_result = ftp_login($conn_id, $ftp_user_name, $ftp_user_pass);

        if (!$login_result) {
            throw new PException("Uploader: moveUploadFileFtp - can not login to FTP"); // не смогли залогиниться
        }

        $target_name_relative = $target_name_relative[0] == '/' ? substr($target_name_relative, 1) : $target_name_relative;
        $dirs = explode('/', $target_name_relative);
        if ($dirs[0] != 'upload') {
            $dirs = array_merge(array('upload'), $dirs);
        }
        /*    полный путь на машине получателе = /root/domains/web/upload/admins/picture/e719661cbbe572e30c2fc63a2ee1948a.jpg
              относительные пути на машине получателя
                /anon/picture/6b006724d7e65c21ed41a1e32a707623.png
                anon/picture/6b006724d7e65c21ed41a1e32a707623.png
                upload/users/picture/123/d91660a762ba45dde669d75ea25831f4.png
                upload/admins/picture/30d11588e44b207aed3cb8021b903c42.png     - если admin_id(owner_id) = empty
                upload/admins/picture/321/63dc54151c01c3e6960a1932d76795b0.png
                upload/anon/picture/6b006724d7e65c21ed41a1e32a707623.png
                upload/avatars/photo-319554.jpg
                upload/photo/7623.png
                avatars/photo-319554.jpg
                photo/7623.png

              вариант 1 - всего может быть 4 поддирректории:
               $dirs[0] = upload  - проверять не надо - она создана - заходить тоже не надо - она установлена домашней для пользователя newupload
               $dirs[1] = users   - проверять не надо - она создана
               $dirs[2] = picture - проверять не надо - она создана
               $dirs[3] = 123     - проверяем, здесь может быть айдишник пользователя или админа

              вариант 2 -
               $dirs[0] = upload  - проверять не надо - она создана - заходить тоже не надо - она установлена домашней для пользователя newupload
               $dirs[1] = photo   - проверять не надо - она создана
        */

        if (!ftp_chdir($conn_id, $dirs[1])) {
            return false; // не удалось изменить директорию на указанную
        }

        if (isset($dirs[3]) && !ftp_chdir($conn_id, $dirs[2])) {
            return false;
        } else {
            // если есть 4 директории
            if (isset($dirs[4])) {
                if (!ftp_chdir($conn_id, $dirs[3])) {
                    ftp_mkdir($conn_id, $dirs[3]);
                    if (!ftp_chdir($conn_id, $dirs[3])) {
                        return false; // не удалось перейти в нужную папку
                    }
                }
            }
        }

        $fp = fopen($tmp_name, 'r');
        $upload_result = ftp_fput($conn_id, $dirs[count($dirs) - 1], $fp, FTP_BINARY);

        ftp_close($conn_id);
        fclose($fp);

        if (!$upload_result) {
            return false; // файл не загружен
        }

        return true;
    }


    /**
     * функция возвращает тип файла
     *
     * @param string $file_name
     * @param $ext
     * @return string
     */
    public static function getFileType($file_name = '', &$ext)
    {
        $exp = explode('.', $file_name);
        $ext = mb_strtolower($exp[count($exp) - 1]);

        if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif')))
            return 'picture';

        if (in_array($ext, array('3gp', 'avi', 'flv', 'm4v', 'mkv', 'mov', 'mp4', 'rm', 'wmv', 'mpeg', 'mpg')))
            return 'video';

        if (in_array($ext, array('ape', 'flac', 'm4a', 'mdi', 'ram', 'mp3', 'ogg', 'wma', 'wav')))
            return 'audio';

        if (in_array($ext, array('gp3', 'jtd', 'lwp', 'ods', 'odt', 'pdf', 'pps', 'ppsx', 'ppt', 'sxw', 'vsd', 'wpd', 'wps', 'xls', '7z', '7zip', 'cbr', 'cbz', 'jar', 'r00', 'rar', 'zip', 'zix', 'doc', 'docx', 'dotx', 'ppsx', 'pptm', 'pptx', 'xlsm', 'xlsx', 'txt', 'rtf', 'vob', 'cue')))
            return 'files';

        throw new PException("Uploader: file type incorrect, file={$file_name}", 21);
    }

}