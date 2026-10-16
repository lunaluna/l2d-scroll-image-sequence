<?php
/**
 * 選んだコマを書き出して検査する L2dsis_Sequences::extract_entries() のテスト.
 *
 * 画像は、PNG の頭(シグネチャと、幅・高さが書かれた IHDR)だけのファイルで作る.
 * getimagesize() が読むのはこの頭だけなので、大きな画像を作らずに寸法の上限を試せる.
 *
 * @package L2dScrollImageSequence
 */

/**
 * 1 コマの画素数と、合計の画素数の上限.
 */
class ExtractEntriesTest extends L2dsis_Test_Case {

	/**
	 * 指定の寸法の PNG の頭を作る.
	 *
	 * @param int $width  幅.
	 * @param int $height 高さ.
	 * @return string PNG のバイト列.
	 */
	private function png( $width, $height ) {
		$ihdr = pack( 'NNCCCCC', $width, $height, 8, 6, 0, 0, 0 );
		return "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . $ihdr . pack( 'N', crc32( 'IHDR' . $ihdr ) );
	}

	/**
	 * ZIP を作り、select_entries() で選んでから extract_entries() を呼ぶ.
	 *
	 * @param array<string, string> $files ZIP の中の名前と中身.
	 * @return array|WP_Error extract_entries() の戻り値.
	 */
	private function extract( array $files ) {
		$path = $this->tmp_dir . '/test.zip';
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		foreach ( $files as $name => $data ) {
			$zip->addFromString( $name, $data );
		}
		$zip->close();

		$zip = new ZipArchive();
		$zip->open( $path );
		$entries = $this->call_private( 'select_entries', array( $zip ) );
		$this->assertIsArray( $entries );

		$work_dir = $this->tmp_dir . '/work';
		mkdir( $work_dir );
		return $this->call_private( 'extract_entries', array( $zip, $entries['entries'], $work_dir ) );
	}

	/**
	 * 上限の内側なら、すべてのコマを書き出す.
	 *
	 * @return void
	 */
	public function test_frames_within_limits_are_extracted() {
		$result = $this->extract(
			array(
				'1.png' => $this->png( 3, 2 ),
				'2.png' => $this->png( 3, 2 ),
			)
		);

		$this->assertSame(
			array(
				'frames'  => 2,
				'skipped' => 0,
			),
			$result
		);
		$this->assertFileExists( $this->tmp_dir . '/work/frames/2.png' );
	}

	/**
	 * 中身が画像でないものは捨てて数える.
	 *
	 * @return void
	 */
	public function test_non_image_content_is_skipped() {
		$result = $this->extract(
			array(
				'1.png'    => $this->png( 3, 2 ),
				'fake.png' => '<?php echo 1;',
			)
		);

		$this->assertSame( 1, $result['frames'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertFileDoesNotExist( $this->tmp_dir . '/work/frames/fake.png' );
	}

	/**
	 * 1 コマの画素数の上限を超えたら止める.
	 *
	 * @return void
	 */
	public function test_frame_pixels_limit_stops() {
		$GLOBALS['l2dsis_test']['filters']['l2dsis_max_frame_pixels'] = 5;

		$result = $this->extract( array( '1.png' => $this->png( 3, 2 ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'l2dsis_frame_too_many_pixels', $result->get_error_code() );
	}

	/**
	 * 合計の画素数の上限を超えたら止める(2026-10-16 の最終レビューの指摘 2).
	 *
	 * @return void
	 */
	public function test_total_pixels_limit_stops() {
		$GLOBALS['l2dsis_test']['filters']['l2dsis_max_total_pixels'] = 10;

		$result = $this->extract(
			array(
				'1.png' => $this->png( 3, 2 ),
				'2.png' => $this->png( 3, 2 ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'l2dsis_total_too_many_pixels', $result->get_error_code() );
	}
}
