<?php
/**
 * ZIP の中の一覧から取り出すコマを選ぶ L2dsis_Sequences::select_entries() のテスト.
 *
 * 実際に ZipArchive で ZIP を作って試す. 中身は画像でなくてよい(ここでは名前と大きさしか見ないため).
 *
 * @package L2dScrollImageSequence
 */

/**
 * 画像の拡張子だけを選び、上限と同じ名前で止まること.
 */
class SelectEntriesTest extends L2dsis_Test_Case {

	/**
	 * ZIP を作って開く.
	 *
	 * @param array<string, string> $files ZIP の中の名前と中身. 名前が / で終わればフォルダ.
	 * @return ZipArchive 開いた ZIP.
	 */
	private function make_zip( array $files ) {
		$path = $this->tmp_dir . '/test-' . bin2hex( random_bytes( 4 ) ) . '.zip';
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		foreach ( $files as $name => $data ) {
			if ( '/' === substr( $name, -1 ) ) {
				$zip->addEmptyDir( rtrim( $name, '/' ) );
			} else {
				$zip->addFromString( $name, $data );
			}
		}
		$zip->close();

		$zip = new ZipArchive();
		$zip->open( $path );
		return $zip;
	}

	/**
	 * Select_entries() を呼ぶ.
	 *
	 * @param ZipArchive $zip ZIP.
	 * @return array|WP_Error 戻り値.
	 */
	private function select( ZipArchive $zip ) {
		return $this->call_private( 'select_entries', array( $zip ) );
	}

	/**
	 * 画像の拡張子のファイルだけを選び、サブフォルダは平らにする. ほかのファイルは数える.
	 *
	 * @return void
	 */
	public function test_selects_images_and_counts_others() {
		$result = $this->select(
			$this->make_zip(
				array(
					'frame-001.webp'          => 'a',
					'sub/'                    => '',
					'sub/frame-002.JPG'       => 'b',
					'frame-003.png'           => 'c',
					'evil.php'                => '<?php',
					'readme.txt'              => 'x',
					'.htaccess'               => 'x',
					'.hidden.webp'            => 'x',
					'__MACOSX/._frame-001.webp' => 'x',
					'empty.webp'              => '',
				)
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'frame-001.webp', 'frame-002.JPG', 'frame-003.png' ), array_keys( $result['entries'] ) );
		// evil.php・readme.txt・.htaccess・.hidden.webp・__MACOSX の中・大きさ 0 の empty.webp. フォルダは数えない.
		$this->assertSame( 6, $result['skipped'] );
	}

	/**
	 * サブフォルダを平らにしたとき、同じ名前が 2 つあれば止める.
	 *
	 * @return void
	 */
	public function test_duplicate_names_stop() {
		$result = $this->select(
			$this->make_zip(
				array(
					'a/frame.webp' => 'a',
					'b/frame.webp' => 'b',
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'l2dsis_duplicate_name', $result->get_error_code() );
	}

	/**
	 * ZIP の中のファイルの数が上限を超えたら止める.
	 *
	 * @return void
	 */
	public function test_too_many_entries_stop() {
		$GLOBALS['l2dsis_test']['filters']['l2dsis_max_zip_entries'] = 2;

		$result = $this->select(
			$this->make_zip(
				array(
					'1.webp' => 'a',
					'2.webp' => 'b',
					'3.txt'  => 'c',
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'l2dsis_too_many_entries', $result->get_error_code() );
	}

	/**
	 * コマの数が上限を超えたら止める.
	 *
	 * @return void
	 */
	public function test_too_many_frames_stop() {
		$GLOBALS['l2dsis_test']['filters']['l2dsis_max_frames'] = 2;

		$result = $this->select(
			$this->make_zip(
				array(
					'1.webp' => 'a',
					'2.webp' => 'b',
					'3.webp' => 'c',
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'l2dsis_too_many_frames', $result->get_error_code() );
	}

	/**
	 * 1 コマの大きさと合計の大きさの上限.
	 *
	 * @return void
	 */
	public function test_size_limits_stop() {
		$GLOBALS['l2dsis_test']['filters']['l2dsis_max_frame_bytes'] = 3;

		$result = $this->select( $this->make_zip( array( 'big.webp' => '1234' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'l2dsis_frame_too_large', $result->get_error_code() );

		$GLOBALS['l2dsis_test']['filters']['l2dsis_max_frame_bytes'] = 10;
		$GLOBALS['l2dsis_test']['filters']['l2dsis_max_total_bytes'] = 5;

		$result = $this->select(
			$this->make_zip(
				array(
					'1.webp' => '123',
					'2.webp' => '456',
				)
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'l2dsis_total_too_large', $result->get_error_code() );
	}
}
