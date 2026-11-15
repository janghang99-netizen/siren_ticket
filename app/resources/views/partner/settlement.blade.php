@extends('layouts.backoffice')
@section('title', '정산서 #'.$settlement->id)
@section('eyebrow', $vendor->name)

@section('actions')
    <a href="{{ route('partner.settlements') }}" class="btn-ghost !py-2.5 text-sm">정산 내역</a>
@endsection

@section('content')
    @include('backoffice.settlement-detail')
@endsection
