<?php

if(!empty($_GET['act'])) {
    switch($_GET['act']) {
        case 'put' :
            put($_POST);
            break;
            
        case 'get' :
            get($_GET['id']);
            break;
            
        case 'all' :
            break;
            
        default:
            all();
//            http_response_code(404);
//            die();
    }
}

    function put($content)
    {
        $fname = time();
        $myfile = fopen($fname.'.log', "w") or die("Unable to open file!");
        $data = array(
            'data'=>$content,
        );
        fwrite($myfile, json_encode($data));
        fclose($myfile);
    }
    
    function get($id=null)
    {
        $fname = $id.'.log';
        
        if($id && is_file($fname)) 
        {
            $myfile = fopen($fname, "r") or die("Unable to open file!");
            $content = fread($myfile,filesize($fname));
            fclose($myfile);
            
            echo '<pre>'; print_r(json_decode($content,true)); echo '</pre>';
        } else
            show_404();
    }

    function all()
    {
        echo '<ul>';
        foreach(glob('*.log') as $f)
        {
            $id = substr(basename($f),0,-4);
            echo '<li><a href="?act=get&id='.$id.'">'.date('Y-m-d H:i:s',$id).'</a></li>';
        }
        echo '</ul>';
    }
    
