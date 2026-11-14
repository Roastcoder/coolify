@extends('proxy.layout')

@section('title', 'Updating StandaloneCoders Cloud')

@section('content')
    <p class="status">Updating</p>
    <h1>StandaloneCoders Cloud is restarting</h1>
    <p>The panel is installing a new version and will be back in about a minute. Your apps and databases keep running.</p>
    <p class="status">This page reloads by itself.</p>
    <script>setTimeout(() => location.reload(), 5000)</script>
@endsection
