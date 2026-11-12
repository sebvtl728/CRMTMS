@extends('errors.layout', ['status' => 429])

@section('title', "Trop de tentatives")
@section('message', "Trop de requêtes en peu de temps. Patientez quelques instants puis réessayez.")
