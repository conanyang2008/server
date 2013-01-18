<?php
/**
 * ownCloud - trash bin
 *
 * @author Bjoern Schiessle
 * @copyright 2013 Bjoern Schiessle schiessle@owncloud.com
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA_Trash;

class Trashbin {
	
	const DELETEAFTER=30; // how long do we keep files in the trash bin (number of days)

	/**
	 * move file to the trash bin
	 * 
	 * @param $file_path path to the deleted file/directory relative to the files root directory
	 */
	public static function move2trash($file_path) {
		$user = \OCP\User::getUser();
		$view = new \OC_FilesystemView('/'. $user);
		if (!$view->is_dir('files_trashbin')) {
			$view->mkdir('files_trashbin');
			$view->mkdir("versions_trashbin");
		}

		$path_parts = pathinfo($file_path);

		$deleted = $path_parts['basename'];
		$location = $path_parts['dirname'];
		$timestamp = time();
		$mime = $view->getMimeType('files'.$file_path);

		if ( $view->is_dir('files'.$file_path) ) {
			$type = 'dir';
		} else {
			$type = 'file';
		}

		self::copy_recursive($file_path, 'files_trashbin/'.$deleted.'.d'.$timestamp, $view);
		
		$query = \OC_DB::prepare("INSERT INTO *PREFIX*files_trash (id,timestamp,location,type,mime,user) VALUES (?,?,?,?,?,?)");
		$result = $query->execute(array($deleted, $timestamp, $location, $type, $mime, $user));

		if ( \OCP\App::isEnabled('files_versions') ) {
			if ( $view->is_dir('files_versions'.$file_path) ) {
				$view->rename('files_versions'.$file_path, 'versions_trashbin/'. $deleted.'.d'.$timestamp);
			} else if ( $versions = \OCA_Versions\Storage::getVersions($file_path) ) {
				foreach ($versions as $v) {
					$view->rename('files_versions'.$v['path'].'.v'.$v['version'], 'versions_trashbin/'. $deleted.'.v'.$v['version'].'.d'.$timestamp);
				}
			}
		}
		
		self::expire();
	}
	
	
	/**
	 * restore files from trash bin
	 * @param $filename name of the file
	 * @param $timestamp time when the file was deleted
	 */
	public static function restore($filename, $timestamp) {

		$user = \OCP\User::getUser();
		$view = new \OC_FilesystemView('/'.$user);
		
		$query = \OC_DB::prepare('SELECT location,type FROM *PREFIX*files_trash WHERE user=? AND id=? AND timestamp=?');
		$result = $query->execute(array($user,$filename,$timestamp))->fetchAll();
		
		if ( count($result) != 1 ) {
			\OC_Log::write('files_trashbin', 'trash bin database inconsistent!', OC_Log::ERROR);
			return false;
		}

		// if location no longer exists, restore file in the root directory
		$location = $result[0]['location'];
		if ( $result[0]['location'] != '/' && !$view->is_dir('files'.$result[0]['location']) ) {
			$location = '/';
		}
		
		$source = 'files_trashbin/'.$filename.'.d'.$timestamp;
		$target = \OC_Filesystem::normalizePath('files/'.$location.'/'.$filename);
		
		// we need a  extension in case a file/dir with the same name already exists
		$ext = self::getUniqueExtension($location, $filename, $view);
		
		if( $view->rename($source, $target.$ext) ) {

			// if versioning app is enabled, copy versions from the trash bin back to the original location
			if ( $return && \OCP\App::isEnabled('files_versions') ) {
				if ( $result[0][type] == 'dir' ) {
					$view->rename('versions_trashbin/'. $filename.'.d'.$timestamp, 'files_versions/'.$location.'/'.$filename.$ext);
				} else if ( $versions = self::getVersionsFromTrash($filename, $timestamp) ) {
					foreach ($versions as $v) {
						$view->rename('versions_trashbin/'.$filename.'.v'.$v.'.d'.$timestamp, 'files_versions/'.$location.'/'.$filename.$ext.'.v'.$v);
					}
				}
			}

			$query = \OC_DB::prepare('DELETE FROM *PREFIX*files_trash WHERE user=? AND id=? AND timestamp=?');
			$query->execute(array($user,$filename,$timestamp));

			return true;
		}

		return false;
	}
	
	/**
	 * clean up the trash bin
	 */
	private static function expire() {
		
		$view = new \OC_FilesystemView('/'.\OCP\User::getUser());
		$user = \OCP\User::getUser();
		
		$query = \OC_DB::prepare('SELECT location,type,id,timestamp FROM *PREFIX*files_trash WHERE user=?');
		$result = $query->execute(array($user))->fetchAll();
		
		$limit = time() - (self::DELETEAFTER * 86400);

		foreach ( $result as $r ) {
			$timestamp = $r['timestamp'];
			$filename = $r['id'];
			if ( $r['timestamp'] < $limit ) {
				$view->unlink('files_trashbin/'.$filename.'.d'.$timestamp);
				if ($r['type'] == 'dir') {
					$view->unlink('versions_trashbin/'.$filename.'.d'.$timestamp);
				} else if ( $versions = self::getVersionsFromTrash($filename, $timestamp) ) {
					foreach ($versions as $v) {
						$view->unlink('versions_trashbin/'.$filename.'.v'.$v.'.d'.$timestamp);
					}			
				}
			}
		}
		
		$query = \OC_DB::prepare('DELETE FROM *PREFIX*files_trash WHERE user=? AND timestamp<?');
		$query->execute(array($user,$limit));
	}
	
	/**
	 * recursive copy to copy a whole directory
	 * 
	 * @param $source source path, relative to the users files directory
	 * @param $destination destination path relative to the users root directoy
	 * @param $view file view for the users root directory
	 * @param $location location of the source files, either "fscache" or "local"
	 */
	private static function copy_recursive( $source, $destination, $view, $location='fscache' ) {
		if ( $view->is_dir( 'files'.$source ) ) {
			$view->mkdir( $destination );
			foreach ( \OC_Files::getDirectoryContent($source) as $i ) {
				$pathDir = $source.'/'.$i['name'];
				if ( $view->is_dir('files'.$pathDir) ) {
					self::copy_recursive($pathDir, $destination.'/'.$i['name'], $view);
				} else {
					$view->copy( 'files'.$pathDir, $destination . '/' . $i['name'] );
				}
			}
		} else {
			$view->copy( 'files'.$source, $destination );
		}
	}
	
	/**
	 * find all versions which belong to the file we want to restore
	 * @param $filename name of the file which should be restored
	 * @param $timestamp timestamp when the file was deleted
	 */
	private static function getVersionsFromTrash($filename, $timestamp) {
		$view = new \OC_FilesystemView('/'.\OCP\User::getUser().'/versions_trashbin');
		$versionsName = \OCP\Config::getSystemValue('datadirectory').$view->getAbsolutePath($filename);
		$versions = array();
		
		// fetch for old versions
		$matches = glob( $versionsName.'.v*.d'.$timestamp );
		
		foreach( $matches as $ma ) {
			$parts = explode( '.v', substr($ma, 0, -strlen($timestamp)-2) );
			$versions[] = ( end( $parts ) );
		}
		return $versions;
	}
	
	/**
	 * find unique extension for restored file if a file with the same name already exists
	 * @param $location where the file should be restored
	 * @param $filename name of the file
	 * @param $view filesystem view relative to users root directory
	 * @return string with unique extension
	 */
	private static function getUniqueExtension($location, $filename, $view) {
		$ext = '';
		if ( $view->file_exists('files'.$location.'/'.$filename) ) {
			$tmpext = '.restored';
			$ext = $tmpext;
			$i = 1;
			while ( $view->file_exists('files'.$location.'/'.$filename.$ext) ) {
				$ext = $tmpext.$i;
				$i++;
			}
		}
		return $ext;
	}

}
