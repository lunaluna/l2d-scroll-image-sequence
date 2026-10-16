<?php
/**
 * ブロックのフロントの描画 src/sequence/render.php のテスト.
 *
 * 2026-10-16【A】の指摘: data-frames・data-frames-mobile のコマの URL が wp_json_encode() の既定で http:\/\/… の形に
 * なり、HTML の中の URL を書き換える仕組み(Local の Live Link・CDN 系のプラグイン)から漏れて、スマホの実機で
 * コマだけが読み込まれなかった. 出力に \/ が無く、普通の URL の形で出ること.
 *
 * @package L2dScrollImageSequence
 */

/**
 * Render.php の出力.
 */
class RenderTest extends L2dsis_Test_Case {

	/**
	 * Render.php を読み込んで、ブロックの HTML を作る.
	 *
	 * @param array $attributes ブロックの属性.
	 * @return string HTML.
	 */
	private function render( array $attributes ) {
		$content = '<h2>Heading</h2>';
		ob_start();
		require dirname( __DIR__ ) . '/src/sequence/render.php';
		return ob_get_clean();
	}

	/**
	 * 公開のシーケンスを用意する.
	 *
	 * @param int      $post_id 投稿 ID.
	 * @param string[] $names   コマのファイル名.
	 * @return void
	 */
	private function sequence( $post_id, array $names ) {
		$this->make_frames( $post_id, $names );
		$GLOBALS['l2dsis_test']['post_types'][ $post_id ]  = L2dsis_Sequences::POST_TYPE;
		$GLOBALS['l2dsis_test']['post_status'][ $post_id ] = 'publish';
	}

	/**
	 * PC 用と SP 用のコマの URL は、\/ を含まない普通の URL の形で、属性に入る.
	 *
	 * @return void
	 */
	public function test_frame_urls_are_not_slash_escaped() {
		$this->sequence( 70, array( 'pc-1.webp', 'pc-2.webp' ) );
		$this->sequence( 71, array( 'sp-1.webp' ) );

		$html = $this->render(
			array(
				'sequenceId'       => 70,
				'sequenceIdMobile' => 71,
			)
		);

		$this->assertStringNotContainsString( '\/', $html );
		$this->assertStringContainsString(
			'data-frames="[&quot;https://example.test/wp-content/uploads/l2d-scroll-image-sequence/70/frames/pc-1.webp&quot;,&quot;https://example.test/wp-content/uploads/l2d-scroll-image-sequence/70/frames/pc-2.webp&quot;]"',
			$html
		);
		$this->assertStringContainsString(
			'data-frames-mobile="[&quot;https://example.test/wp-content/uploads/l2d-scroll-image-sequence/71/frames/sp-1.webp&quot;]"',
			$html
		);
	}

	/**
	 * 属性の値は今までどおりエスケープされる(JSON の " が &quot; になり、属性から抜け出さない).
	 *
	 * @return void
	 */
	public function test_attribute_values_are_still_escaped() {
		$this->sequence( 72, array( 'a"b.webp' ) );

		$html = $this->render( array( 'sequenceId' => 72 ) );

		$this->assertStringNotContainsString( '\/', $html );
		$this->assertMatchesRegularExpression( '/data-frames="\[&quot;[^"]*a%22b\.webp&quot;\]"/', $html );
	}

	/**
	 * ページの中で最初のブロックだけ、1 コマ目を先読みし fetchpriority="high". 2 つ目は loading="lazy" で先読みしない.
	 *
	 * @return void
	 */
	public function test_only_first_block_preloads_first_frame() {
		$this->sequence( 73, array( 'a-1.webp', 'a-2.webp' ) );
		$this->sequence( 74, array( 'b-1.webp' ) );

		$first  = $this->render( array( 'sequenceId' => 73 ) );
		$second = $this->render( array( 'sequenceId' => 74 ) );

		$this->assertStringContainsString( 'fetchpriority="high"', $first );
		$this->assertStringNotContainsString( 'loading="lazy"', $first );
		$this->assertStringContainsString( 'loading="lazy"', $second );
		$this->assertStringNotContainsString( 'fetchpriority', $second );

		$this->assertSame(
			array(
				array(
					'href'          => 'https://example.test/wp-content/uploads/l2d-scroll-image-sequence/73/frames/a-1.webp',
					'as'            => 'image',
					'fetchpriority' => 'high',
				),
			),
			L2dsis_Sequences::add_preload_resources( array() )
		);
	}

	/**
	 * SP 用があるときは、SP 用と PC 用の 1 コマ目を media で出し分けて先読みする(両方を読まない).
	 *
	 * @return void
	 */
	public function test_preload_is_split_by_media_with_mobile_sequence() {
		$this->sequence( 75, array( 'pc-1.webp' ) );
		$this->sequence( 76, array( 'sp-1.webp' ) );

		$this->render(
			array(
				'sequenceId'       => 75,
				'sequenceIdMobile' => 76,
			)
		);

		$preloads = L2dsis_Sequences::add_preload_resources( array( array( 'href' => 'other.css' ) ) );
		$this->assertSame( 'other.css', $preloads[0]['href'], 'ほかの先読みは残す' );
		$this->assertSame( '(max-width: 1024px)', $preloads[1]['media'] );
		$this->assertStringEndsWith( '/76/frames/sp-1.webp', $preloads[1]['href'] );
		$this->assertSame( '(min-width: 1025px)', $preloads[2]['media'] );
		$this->assertStringEndsWith( '/75/frames/pc-1.webp', $preloads[2]['href'] );
	}

	/**
	 * シーケンスが無いブロックは「最初のブロック」に数えない(あとのブロックの先読みを妨げない).
	 *
	 * @return void
	 */
	public function test_empty_block_does_not_take_first_place() {
		$this->sequence( 77, array( 'c-1.webp' ) );

		$this->render( array( 'sequenceId' => 0 ) );
		$html = $this->render( array( 'sequenceId' => 77 ) );

		$this->assertStringContainsString( 'fetchpriority="high"', $html );
	}

	/**
	 * シーケンスが無いときは、コマの属性は空で、.is-empty になる.
	 *
	 * @return void
	 */
	public function test_empty_sequence() {
		$html = $this->render( array( 'sequenceId' => 0 ) );

		$this->assertStringContainsString( 'class="l2dsis-sequence is-empty"', $html );
		$this->assertStringContainsString( 'data-frames=""', $html );
		$this->assertStringContainsString( '<h2>Heading</h2>', $html );
	}
}
