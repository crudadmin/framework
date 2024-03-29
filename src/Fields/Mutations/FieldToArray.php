<?php

namespace Admin\Core\Fields\Mutations;

class FieldToArray
{
    /**
     * Bind value of each key from format "attribute:value" or "attribute".
     *
     * @param  array  $row
     * @return mixed
     */
    protected function bindValue($row)
    {
        $count = count($row);

        if ($count == 1) {
            return true;
        }

        if ($count == 2) {
            return $row[1];
        }
        if ($count > 2) {
            return implode(':', array_slice($row, 1));
        }
    }

    /**
     * Update field entity and change string format into array format.
     *
     * @param  array  $field
     * @return array
     */
    public function update($field)
    {
        $data = [];

        if (is_string($field)) {
            $field = preg_replace('/\|+/', '|', $field);

            $fields = explode('|', $field);

            foreach ($fields as $k => $value) {
                $row = explode(':', $value);

                if (array_key_exists($row[0], $data)) {
                    //If property has multiple properties yet
                    if (is_array($data[$row[0]])) {
                        $data[$row[0]][] = $this->bindValue($row);
                    } else {
                        $data[$row[0]] = [$data[$row[0]], $this->bindValue($row)];
                    }
                } else {
                    $data[$row[0]] = $this->bindValue($row);
                }
            }
        } else {

            //Bind values without keys as key => true (required -> true)
            foreach ($field as $key => $value) {
                if (is_numeric($key) && is_string($value)) {
                    $data[$value] = true;
                } else {
                    $data[$key] = $value;
                }
            }
        }

        return $data;
    }
}
