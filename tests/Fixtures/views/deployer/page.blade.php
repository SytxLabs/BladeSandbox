@extends('deployer::layout')

@section('title', 'Page ' . $deployment->name)

@section('content')
<main>{{ $deployment->status }}</main>
@include('deployer::partials.footer')
@endsection

@push('scripts')
<i>pushed</i>
@endpush
