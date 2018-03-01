<?php

namespace Tsugi\Blob;

use \Tsugi\Core\LTIX;
use \Tsugi\Blob\BlobUtil;

class Access {

    public static function serveContent() {
        global $CFG, $CONTEXT, $PDOX;
        // Sanity checks
        $LAUNCH = LTIX::requireData();

        $id = $_REQUEST['id'];
        if ( strlen($id) < 1 ) {
            die("File not found");
        }

        $p = $CFG->dbprefix;

        // https://bugs.php.net/bug.php?id=40913
        // Note - the "stream to blob" is still broken in PHP 7 so we do two separate selects
        $lob = false;
        $file_path = false;
        $stmt = $PDOX->prepare("SELECT BF.contenttype, BF.path, BF.file_name, BB.blob_id
            FROM {$p}blob_file AS BF
            LEFT JOIN {$p}blob_blob AS BB ON BF.file_sha256 = BB.blob_sha256
                AND BF.blob_id = BB.blob_id AND BB.content IS NOT NULL
            WHERE file_id = :ID AND context_id = :CID AND (link_id = :LID OR link_id IS NULL)");
        $stmt->execute(array(":ID" => $id, ":CID" => $LAUNCH->context->id, ":LID" => $LAUNCH->link->id));
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ( $row === false ) {
            error_log('File not loaded: '.$id);
            die("File not loaded");
        }
        $type = $row['contenttype'];
        $file_name = $row['file_name'];
        $file_path = $row['path'];
        $blob_id = $row['blob_id'];
        $lob = null;
	$source = 'file';

        if ( ! BlobUtil::safeFileSuffix($file_name) )  {
            error_log('Unsafe file suffix: '.$file_name);
            die('Unsafe file suffix');
        }

        // Check to see if the path is there
        if ( $file_path ) {
            if ( ! file_exists($file_path) ) {
                error_log("Missing file path if=$id file_path=$file_path");
                $file_path = false;
            }
        }

        // Is the blob is in the single instance table?
        if ( ! $file_path && $blob_id ) {
            // http://php.net/manual/en/pdo.lobs.php
            $stmt = $PDOX->prepare("SELECT content FROM {$p}blob_blob WHERE blob_id = :ID");
            $stmt->execute(array(":ID" => $blob_id));
            $stmt->bindColumn(1, $lob, \PDO::PARAM_LOB);
            $stmt->fetch(\PDO::FETCH_BOUND);
            $source = 'blob_blob';
        }

        // Fall back to the "in-row" blob
        if ( !$file_path && ! $lob ) {
            $stmt = $PDOX->prepare("SELECT content FROM {$p}blob_file WHERE file_id = :ID");
            $stmt->execute(array(":ID" => $id));
            $stmt->bindColumn(1, $lob, \PDO::PARAM_LOB);
            $stmt->fetch(\PDO::FETCH_BOUND);
            $source = 'blob_file';
        }

        if ( !$file_path && ! $lob ) {
            error_log("No file contents file_id=$id file_path=$file_path blob_id=$blob_id");
            die('Unable to find file contents');
        }

        // Update the access time in the file table
        $stmt = $PDOX->queryDie("UPDATE {$p}blob_file SET accessed_at=NOW()
            WHERE file_id = :ID", array(":ID" => $id)
        );

        // Update the access time in the single instance blob table
        if ( $blob_id ) {
            $stmt = $PDOX->queryDie("UPDATE {$p}blob_blob SET accessed_at=NOW()
                    WHERE blob_id = :BID",
                array(":BID" => $blob_id)
            );
        }

	header('X-Tsugi-Data-Source: '.$source);
        if ( strlen($type) > 0 ) header('Content-Type: '.$type );
        // header('Content-Disposition: attachment; filename="'.$file_name.'"');
        // header('Content-Type: text/data');

        if ( $file_path ) {
            error_log("file serve id=$id name=$file_name mime=$type path=$file_path");
            echo readfile($file_path);
        } else if ( is_string($lob) ) {
            error_log("string blob id=$id name=$file_name mime=$type");
            echo($lob);
        } else {
            error_log("resource blob id=$id name=$file_name mime=$type");
            fpassthru($lob);
        }
    }
}
