@extends('layouts.app')
@section('content')
  @php
    do_action('alps_custom_sidebar_widgets');

    $isVisibleSidebar = \App\TemplateHelpers::isVisibleSidebarOnArchive();

    $sidebar = [
      'id' => 'sidebar-posts',
      'isVisible' => $isVisibleSidebar,
    ];

    /**
     * Author page (App\Authors): the author's name in the header, then the
     * photo, bio and links above their posts.
     */
    $alpsAuthor = get_queried_object();
    $headerType = \App\TemplateHelpers::POST_HEADER_TYPE_SIMPLE;
    $headerTitle = esc_html($alpsAuthor->name);
    $headerKicker = __('Author', 'alps');
    $postsIntro = view('partials.author-header', ['author' => $alpsAuthor])->render();
  @endphp

  @include('patterns.02-organisms.sections.page-header-v2')
  @include('patterns.02-organisms.content.content-posts')

@endsection
