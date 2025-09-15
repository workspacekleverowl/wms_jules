@extends('masters::layouts.master')

@section('content')
    <h1>Hello World</h1>

    <p>Module: {!! config('masters.name') !!}</p>
@endsection
