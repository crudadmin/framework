<?php

namespace Admin\Core\Requests;

class AdminModelRequest
{
    /**
     * Use only given fields from admin model
     *
     * @example ['key_a', 'key_b']
     *
     * @return  array
     */
    public function only()
    {
        return [];
    }

    /**
     * Use only given fields with validation format, but also merge additional fields
     *
     * @example  [ 'key_1' => 'required|min:5', 'key_2' => 'required|email' ]
     *
     * @return  array
     */
    public function rules()
    {
        return [];
    }

    /**
     * Megre additional validation rules into request
     *
     * @example [ 'field' => 'name:email' ]
     *
     * @return  array
     */
    public function merge()
    {
        return [];
    }

    /**
     * Replace existing validation rules with attached ones
     *
     * @example [ 'field' => 'name:email' ]
     *
     * @return  array
     */
    public function replace()
    {
        return [];
    }
}

?>