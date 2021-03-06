<?php

/**
 * Created by PhpStorm.
 * User: Andrew
 * Date: 10/31/2015
 * Time: 11:32 AM
 */
class InstagramUploader
{

    public $username;
    public $password;
    public $caption;
    public $userAgent = 'Instagram 6.21.2 Android (19/4.4.2; 480dpi; 1152x1920; Meizu; MX4; mx4; mt6595; en_US)';
    public $fileName;
    public $instagramSignature = '25eace5393646842f0d0c3fb2ac7d3cfa15c052436ee86b5406a8433f54d24a5';
    public $unlinkPaths = array();

    function __construct($username, $password, $caption, $fileName)
    {
        session_start();
        $this->username = $username;
        $this->password = $password;
        $this->caption = $caption;
        $this->fileName = $fileName;
    }

    private function GenerateGuid()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(16384, 20479),
            mt_rand(32768, 49151),
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(0, 65535));
    }

    private function GenerateSignature($data)
    {
        return hash_hmac('sha256', $data, $this->instagramSignature);
    }

    private function ConvertImage($originalImage, $outputImage, $quality)
    {
        // jpg, png, gif or bmp?
        $exploded = explode('.', $originalImage);
        $ext = $exploded[count($exploded) - 1];

        if (preg_match('/jpg|jpeg/i', $ext))
            $imageTmp = imagecreatefromjpeg($originalImage);
        else if (preg_match('/png/i', $ext))
            $imageTmp = imagecreatefrompng($originalImage);
        else if (preg_match('/gif/i', $ext))
            $imageTmp = imagecreatefromgif($originalImage);
        else if (preg_match('/bmp/i', $ext))
            $imageTmp = imagecreatefrombmp($originalImage);
        else
            return 0;

        // quality is a value from 0 (worst) to 100 (best)
        imagejpeg($imageTmp, $outputImage, $quality);
        imagedestroy($imageTmp);

        return 1;
    }

    private function SquareImage($imgSrc, $imgDes, $thumbSize = 1000)
    {
        list($width, $height) = getimagesize($imgSrc);
        $myImage = imagecreatefromjpeg($imgSrc);
        if ($width > $height) {
            $y = 0;
            $x = ($width - $height) / 2;
            $smallestSide = $height;
        } else {
            $x = 0;
            $y = ($height - $width) / 2;
            $smallestSide = $width;
        }
        $thumb = imagecreatetruecolor($thumbSize, $thumbSize);
        imagecopyresampled($thumb, $myImage, 0, 0, $x, $y, $thumbSize, $thumbSize, $smallestSide, $smallestSide);
        if (file_exists($imgSrc)) {
            unlink($imgSrc);
        }
        imagejpeg($thumb, $imgDes, 100);
        @imagedestroy($myImage);
        @imagedestroy($thumb);
    }

    function GetPostData($filename)
    {
        $path = getcwd() .'/uploads/' . $filename;
        if (!$path) {
            echo "The image doesn't exist " . $path;
        } else {
            $withoutExt = preg_replace('/\\.[^.\\s]{3,4}$/', '', $filename);
            $convertImageToJpg = $this->ConvertImage($path, './uploads/' . 'converted_' . $withoutExt . '.jpg', 100);
            if ($convertImageToJpg) {
                $convertedPath =  getcwd() .'/uploads/' . 'converted_' . $withoutExt . '.jpg';
                $instagramPath =  getcwd() .'/uploads/' . 'instagram_' . $withoutExt . '.jpg';
                $this->SquareImage($convertedPath, $instagramPath);
                $post_data = array('device_timestamp' => time());
                if ((version_compare(PHP_VERSION, '5.5') >= 0)) {
                    $post_data['photo'] = new CURLFile(realpath($instagramPath));
                } else {
                    $aPost['photo'] = "@".realpath($instagramPath);
                }
                array_push($this->unlinkPaths, $convertedPath, $path, $instagramPath);
                return $post_data;
            }
        }
    }

    public function PostImage()
    {
        $guid = $this->GenerateGuid();
        $device_id = "android-" . $guid;
        $data = '{"device_id":"' . $device_id . '","guid":"' . $guid . '","username":"' . $this->username . '","password":"' . $this->password . '","Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"}';

        $sig = $this->GenerateSignature($data);
        $data = 'signed_body=' . $sig . '.' . urlencode($data) . '&ig_sig_key_version=6';
        $login = $this->SendRequest('accounts/login/', true, $data, $this->userAgent, false);
        if (strpos($login[1], "Sorry, an error occurred while processing this request.")) {
            echo "Request failed, there's a chance that this proxy/ip is blocked";
            $this->CleanImages();
        } else {
            if (empty($login[1])) {
                echo "Empty response received from the server while trying to login";
                $this->CleanImages();
            } else {
                // Decode the array that is returned
                $obj = @json_decode($login[1], true);

                if (empty($obj)) {
                    echo "Could not decode the response: ";
                    $this->CleanImages();
                } else {
                    // Post the picture
                    $data = $this->GetPostData($this->fileName);
                    $post = $this->SendRequest('media/upload/', true, $data, $this->userAgent, true);
                    var_dump($post);
                    if (empty($post[1])) {
                        echo "Empty response received from the server while trying to post the image";
                        $this->CleanImages();
                    } else {

                        $obj = @json_decode($post[1], true);

                        if (empty($obj)) {
                            echo "Could not decode the response";
                            $this->CleanImages();
                        } else {
                            $status = $obj['status'];

                            if ($status == 'ok') {
                                // Remove and line breaks from the caption
                                $caption = preg_replace("/\r|\n/", "", $this->caption);
                                $media_id = $obj['media_id'];
                                $device_id = "android-" . $guid;
                                $data = '{"device_id":"' . $device_id . '","guid":"' . $guid . '","media_id":"' . $media_id . '","caption":"' . trim($caption) . '","device_timestamp":"' . time() . '","source_type":"5","filter_type":"0","extra":"{}","Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"}';
                                $sig = $this->GenerateSignature($data);
                                $new_data = 'signed_body=' . $sig . '.' . urlencode($data) . '&ig_sig_key_version=4';

                                // Now, configure the photo
                                $conf = $this->SendRequest('media/configure/', true, $new_data, $this->userAgent, true);

                                if (empty($conf[1])) {
                                    echo "Empty response received from the server while trying to configure the image";
                                    $this->CleanImages();
                                } else {
                                    if (strpos($conf[1], "login_required")) {
                                        echo "You are not logged in. There's a chance that the account is banned or a captcha is required";
                                        $this->CleanImages();
                                    } else {
                                        $obj = @json_decode($conf[1], true);
                                        $status = $obj['status'];
                                        $failed = strpos($status, 'fail');
                                        if (!$failed) {
                                            echo "Success";
                                            $this->CleanImages();
                                        } else {
                                            echo 'Failed';
                                            $this->CleanImages();
                                        }
                                    }
                                }
                            } else {
                                echo "Status isn't okay";
                                $this->CleanImages();
                            }
                        }
                    }
                }
            }
        }
    }

    private function  CleanImages()
    {
        foreach ($this->unlinkPaths as $path) {
            //deletes images from server
            unlink($path);
        }
    }

    private function SendRequest($url, $post, $post_data, $user_agent, $cookies) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://i.instagram.com/api/v1/'.$url);
        curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        if($post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);
        }
        if ((version_compare(PHP_VERSION, '5.5') >= 0)) {
            curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);
        } 
        if($cookies) {
            curl_setopt($ch, CURLOPT_COOKIEFILE,   dirname(__FILE__). '/cookies.txt');            
        } else {
            curl_setopt($ch, CURLOPT_COOKIEJAR,  dirname(__FILE__). '/cookies.txt');
        }
        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return array($http, $response);
    }

}
